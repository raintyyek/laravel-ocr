<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Extractors;

use Raintyyek\Ocr\Contracts\DocumentExtractor;
use Raintyyek\Ocr\Documents\ExtractedDocument;
use Raintyyek\Ocr\Documents\Field;
use Raintyyek\Ocr\Documents\LineItem;
use Raintyyek\Ocr\Documents\Money;
use Raintyyek\Ocr\Documents\Party;
use Raintyyek\Ocr\Documents\PaymentInfo;
use Raintyyek\Ocr\Documents\TaxLine;
use Raintyyek\Ocr\DTO\OcrResult;
use Raintyyek\Ocr\Enums\DocumentType;
use Raintyyek\Ocr\OcrManager;
use Raintyyek\Ocr\Support\FieldNormalizer;
use Raintyyek\Ocr\Support\ImageSource;

/**
 * Offline, rule-based document extractor with **English / Malay / Chinese**
 * label recognition.
 *
 * It first runs OCR (via the configured engine), then parses the text with
 * multilingual label/keyword and spatial heuristics. It is free (no per-document
 * API fee beyond the OCR itself), works without a cloud "expense" product, and
 * is the primary source for **payment-slip** fields (method, reference,
 * transaction id) that invoice-focused cloud parsers rarely return.
 *
 * Language coverage is aimed at Malaysian documents (mixed en/ms/zh are common):
 * labels, month names (Malay + Chinese 年月日), currencies (RM/MYR, 元/¥/CNY, …)
 * and amount grouping (1,234.56 and 1.234,56) are all handled.
 *
 * Accuracy is best-effort: every field carries a confidence and the raw text it
 * came from, so consumers can threshold and review. The parsing core
 * ({@see parse()}) is pure — give it an {@see OcrResult} and it returns an
 * {@see ExtractedDocument} with no I/O, which keeps it easy to test.
 *
 * @see docs/ROADMAP-1.0.md
 */
final class HeuristicExtractor implements DocumentExtractor
{
    // --- Amount patterns (raw, no delimiters) --------------------------------
    private const CUR = '(?:RM|MYR|USD|SGD|EUR|GBP|AUD|IDR|THB|PHP|JPY|CNY|HKD|RMB|HK\$|S\$|\$|€|£|¥|元)';
    /** Amount ending in 2 decimals (typical on documents). */
    private const MONEY_STRICT = '(?:' . self::CUR . '\s*)?\(?-?\d{1,3}(?:[.,]\d{3})*[.,]\d{2}\)?\s*(?:元)?';
    /** Looser amount (decimals optional) — only used when a label anchors it. */
    private const MONEY_LOOSE = '(?:' . self::CUR . '\s*)?\(?-?\d[\d.,]*\)?\s*(?:元)?';
    /** Unit-of-measure tokens (en/ms/zh) that may trail a line-item quantity. */
    private const UNITS = 'pcs|pc|unit|units|nos|set|sets|box|boxes|btl|bottle|pkt|pack|packs|roll|dozen|doz|pair|pairs|kg|g|gram|grams|ltr|liter|litre|ml|pax|biji|keping|kotak|helai|batang|个|個|件|张|張|包|盒|瓶|台|支|条|條|双|雙';

    // --- Label patterns (raw en|ms|zh, compiled via re()) --------------------
    private const INVOICE_NO = '\b(?:tax\s+)?invoice\s*(?:no\.?|number|#|id)\b|\breceipt\s*(?:no\.?|number|#)\b|\b(?:no\.?|nombor)\s*(?:invois|inbois|resit)\b|\b(?:invois|resit)\s*(?:no\.?|#)\b|发票号码|發票號碼|发票号|發票號|收据号码|收據號碼|单号|單號';
    private const PO_NO      = '\b(?:p\.?\s*o\.?|purchase\s*order)\s*(?:no\.?|number|#)?\b|\b(?:no\.?\s*)?pesanan\s*belian\b|\bno\.?\s*po\b|采购订单号?|採購訂單號?|订单号码|訂單號碼|订单号|訂單號';
    private const DATE       = '\bdate\b|\btarikh\b|日期';
    private const DUE_DATE   = '\bdue\s*date\b|\bdate\s*due\b|\bpayment\s*due\b|\btarikh\s*(?:tempoh|akhir\s*bayaran|matang)\b|\btempoh\s*bayaran\b|到期日|付款到期日|截止日期';
    private const PAY_DATE   = '\bpayment\s*date\b|\bpaid\s*on\b|\bdate\s*paid\b|\btransaction\s*date\b|\btarikh\s*(?:bayaran|pembayaran|transaksi)\b|\bdibayar\s*pada\b|付款日期|支付日期|交易日期';
    private const SUBTOTAL   = '\bsub[\s-]*total\b|\bjumlah\s*kecil\b|\bsub\s*jumlah\b|\bsubjumlah\b|小计|小計';
    private const TOTAL      = '\bgrand\s*total\b|\btotal\s*amount\s*payable\b|\btotal\s*payable\b|\btotal\s*amount\b|\btotal\b|\bamount\s*payable\b|\bjumlah\s*(?:besar|keseluruhan|perlu\s*dibayar)\b|\bjumlah\b|总计|總計|合计|合計|总金额|總金額|总额|總額|应付金额|應付金額|总共|總共';
    private const TAX        = '\b(?:gst|sst|vat)\b|\btax\b(?!\s*invoice)|\bcukai(?:\s*(?:jualan|perkhidmatan))?\b|消费税|消費稅|销售税|銷售稅|服务税|服務稅|税额|稅額|税费|稅費';
    private const DISCOUNT   = '\bdiscount\b|\bdiskaun\b|折扣|优惠|優惠|折让|折讓';
    private const SHIPPING   = '\b(?:shipping|delivery|freight|postage)\b|\b(?:kos\s*)?penghantaran\b|运费|運費|邮费|郵費|配送费|配送費|快递费|快遞費';
    private const AMOUNT_PAID = '\b(?:amount\s*paid|paid\s*amount|payment\s*received)\b|\b(?:(?:jumlah|amaun)\s*dibayar|telah\s*dibayar)\b|已付款|已付|实付|實付|付款金额|付款金額|已收|实收|實收';
    private const BALANCE_DUE = '\b(?:balance\s*due|amount\s*due|outstanding|balance)\b|\bbaki(?:\s*perlu\s*dibayar)?\b|\bamaun\s*perlu\s*dibayar\b|应付账款|應付賬款|结余|結餘|余额|餘額|未付|尚欠|欠款';
    private const PAY_REF    = '\b(?:payment\s*ref(?:erence)?|ref(?:erence)?|approval\s*code)\s*(?:no\.?|number|#)?|\b(?:no\.?\s*)?ruj(?:ukan)?\s*(?:no\.?|#)?|\brujukan\b|参考编号|參考編號|参考号码|參考號碼|参考号|參考號|参考|參考';
    private const TXN_ID     = '\b(?:transaction\s*(?:id|no\.?|ref)|txn\s*(?:id|no\.?)|trace\s*no\.?)\b|\b(?:no\.?\s*)?transaksi\b|\bid\s*transaksi\b|交易编号|交易編號|交易号码|交易號碼|交易号|交易號|流水号|流水號';

    // Confidence bands per strategy.
    private const CONF_LABELED = 0.55;
    private const CONF_DATE    = 0.50;
    private const CONF_VENDOR  = 0.35;
    private const CONF_ITEM    = 0.40;

    /**
     * @param array<string, mixed> $config The `ocr.extraction` config block.
     */
    public function __construct(
        private readonly OcrManager $engines,
        private readonly array $config = [],
    ) {
    }

    public function name(): string
    {
        return 'heuristic';
    }

    public function extract(ImageSource $image, array $options = []): ExtractedDocument
    {
        $result = $this->engines->engine($options['engine'] ?? null)->recognize($image, $options);

        return $this->parse($result, $options);
    }

    /**
     * Parse an already-OCR'd result into a structured document. Pure (no I/O).
     *
     * @param array<string, mixed> $options
     */
    public function parse(OcrResult $result, array $options = []): ExtractedDocument
    {
        $text     = $this->normalize($result->text);
        $lines    = $this->lines($text);
        $language = $this->detectLanguage($text);
        $dayFirst = $this->isDayFirst($options);
        $currency = FieldNormalizer::currency($text) ?? ($options['currency'] ?? ($this->config['currency'] ?? null));

        $excludeDates = $this->re(self::DUE_DATE . '|' . self::PAY_DATE);
        $taxes        = $this->extractTaxes($lines, $currency);

        return new ExtractedDocument(
            type: $this->detectType($text, $options),
            currency: $currency,
            vendor: $this->extractVendor($lines),
            invoiceNumber: $this->labeledField($lines, $this->re(self::INVOICE_NO)),
            poNumber: $this->labeledField($lines, $this->re(self::PO_NO)),
            issueDate: $this->dateField($lines, $this->re(self::DATE), $dayFirst, $excludeDates),
            dueDate: $this->dateField($lines, $this->re(self::DUE_DATE), $dayFirst),
            paymentDate: $this->dateField($lines, $this->re(self::PAY_DATE), $dayFirst),
            subtotal: $this->moneyField($lines, $this->re(self::SUBTOTAL), $currency),
            taxTotal: $this->taxTotalField($taxes, $lines, $currency),
            discountTotal: $this->moneyField($lines, $this->re(self::DISCOUNT), $currency),
            shipping: $this->moneyField($lines, $this->re(self::SHIPPING), $currency),
            total: $this->moneyField($lines, $this->re(self::TOTAL), $currency, $this->re(self::SUBTOTAL), lastMatch: true),
            amountPaid: $this->moneyField($lines, $this->re(self::AMOUNT_PAID), $currency),
            balanceDue: $this->moneyField($lines, $this->re(self::BALANCE_DUE), $currency),
            taxes: $taxes,
            lineItems: $this->extractLineItems($lines, $currency),
            payment: $this->extractPayment($lines, $text),
            source: $result,
            meta: ['extractor' => $this->name(), 'language' => $language],
        );
    }

    // ---------------------------------------------------------------------
    // Field strategies
    // ---------------------------------------------------------------------

    private function detectType(string $text, array $options): DocumentType
    {
        if (isset($options['as'])) {
            return $options['as'] instanceof DocumentType
                ? $options['as']
                : (DocumentType::tryFrom((string) $options['as']) ?? DocumentType::Unknown);
        }

        return match (true) {
            (bool) preg_match('/credit\s*note|nota\s*kredit|贷记单|貸記單|信用票据/iu', $text) => DocumentType::CreditNote,
            (bool) preg_match('/payment\s*(?:slip|advice|receipt)|remittance|transfer\s*(?:receipt|slip)|resit\s*bayaran|slip\s*(?:bayaran|pembayaran)|penyata\s*bayaran|付款(?:单|單|凭证|憑證|收据|收據)|转账(?:凭证|回单)|轉賬(?:憑證|回單)|汇款单|匯款單/iu', $text) => DocumentType::PaymentSlip,
            (bool) preg_match('/tax\s*invoice|invoice|invois(?:\s*cukai)?|发票|發票|税单|稅單/iu', $text) => DocumentType::Invoice,
            (bool) preg_match('/official\s*receipt|receipt|resit|收据|收據|收条|收條/iu', $text) => DocumentType::Receipt,
            (bool) preg_match('/statement|penyata|\bbill\b|\bbil\b|账单|賬單|结单|結單/iu', $text) => DocumentType::Bill,
            default => DocumentType::Unknown,
        };
    }

    /** Detect the dominant language for downstream hints and reporting. */
    private function detectLanguage(string $text): string
    {
        $han   = preg_match_all('/\p{Han}/u', $text);
        $latin = preg_match_all('/[A-Za-z]/', $text);

        if ($han > 0 && $han * 3 >= $latin) {
            return 'zh';
        }

        if (preg_match('/\b(?:jumlah|cukai|tarikh|resit|invois|bayaran|amaun|baki|diskaun|kuantiti|harga|jualan)\b/iu', $text)) {
            return 'ms';
        }

        return 'en';
    }

    /** Guess the vendor as the first substantive line (often the letterhead). */
    private function extractVendor(array $lines): ?Party
    {
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || mb_strlen($line) < 3) {
                continue;
            }
            if (preg_match($this->re(self::INVOICE_NO . '|' . self::DATE . '|receipt|resit|invoice|invois|发票|收据|收據'), $line)) {
                continue;
            }

            return new Party(name: $line);
        }

        return null;
    }

    /**
     * Find a labeled string value: the text after a label on the same line, or
     * the next non-empty line if the label sits alone.
     */
    private function labeledField(array $lines, string $pattern): ?Field
    {
        foreach ($lines as $i => $line) {
            if (! preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $after = trim(substr($line, $m[0][1] + strlen($m[0][0])));
            $after = ltrim($after, " \t:#-.）)");

            if ($after !== '') {
                $value = preg_split('/\s{2,}|\s(?=[A-Z][a-z])/', $after)[0] ?? $after;

                return new Field(trim($value), self::CONF_LABELED, $line);
            }

            $next = $this->nextNonEmpty($lines, $i);
            if ($next !== null) {
                return new Field($next, self::CONF_LABELED * 0.9, $line);
            }
        }

        return null;
    }

    /** Plain labeled string value (used by payment fields). */
    private function labeledValue(array $lines, string $pattern): ?string
    {
        return $this->labeledField($lines, $pattern)?->value;
    }

    /**
     * Find a labeled date and normalize it to Y-m-d. Lines matching $exclude are
     * skipped (e.g. so the issue-date scan ignores "due"/"payment" date lines).
     */
    private function dateField(array $lines, string $pattern, bool $dayFirst, ?string $exclude = null): ?Field
    {
        foreach ($lines as $i => $line) {
            if (! preg_match($pattern, $line) || ($exclude !== null && preg_match($exclude, $line))) {
                continue;
            }

            foreach ([$line, $this->nextNonEmpty($lines, $i) ?? ''] as $candidate) {
                if (preg_match($this->dateRegex(), $candidate, $dm)) {
                    $normalized = FieldNormalizer::date($dm[0], $dayFirst);
                    if ($normalized !== null) {
                        return new Field($normalized, self::CONF_DATE, trim($dm[0]));
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find a labeled monetary amount. When $lastMatch is true the bottom-most
     * matching line wins (grand totals sit at the end); $exclude skips lines that
     * also match another label (e.g. keep "Total" from matching "Sub Total").
     */
    private function moneyField(array $lines, string $pattern, ?string $currency, ?string $exclude = null, bool $lastMatch = false): ?Field
    {
        $found = null;

        foreach ($lines as $i => $line) {
            if (! preg_match($pattern, $line) || ($exclude !== null && preg_match($exclude, $line))) {
                continue;
            }

            $candidates = [$line, $this->nextNonEmpty($lines, $i) ?? ''];

            foreach ([false, true] as $loose) {
                foreach ($candidates as $candidate) {
                    $money = $this->lastAmount($candidate, $currency, $loose);
                    if ($money !== null) {
                        $field = new Field($money, self::CONF_LABELED, trim($candidate));
                        if (! $lastMatch) {
                            return $field;
                        }
                        $found = $field;
                        continue 3; // next line
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Extract tax components (type, rate %, amount). A document may list several.
     *
     * @return list<TaxLine>
     */
    private function extractTaxes(array $lines, ?string $currency): array
    {
        $taxes   = [];
        $pattern = $this->re(self::TAX);

        foreach ($lines as $line) {
            if (! preg_match($pattern, $line)) {
                continue;
            }

            $rate   = preg_match('/(\d{1,2}(?:\.\d+)?)\s*%/', $line, $rm) ? (float) $rm[1] : null;
            $amount = $this->lastAmount($line, $currency, false) ?? $this->lastAmount($line, $currency, true);

            if ($rate === null && $amount === null) {
                continue;
            }

            $taxes[] = new TaxLine($this->taxType($line), $rate, $amount);
        }

        return $taxes;
    }

    private function taxType(string $line): ?string
    {
        return match (true) {
            (bool) preg_match('/\bgst\b/i', $line)                 => 'GST',
            (bool) preg_match('/\bsst\b/i', $line)                 => 'SST',
            (bool) preg_match('/\bvat\b/i', $line)                 => 'VAT',
            (bool) preg_match('/cukai|jualan|perkhidmatan/iu', $line) => 'Cukai',
            (bool) preg_match('/消费税|消費稅|销售税|銷售稅|服务税|服務稅|税/u', $line) => '税',
            default                                                => null,
        };
    }

    /** Tax total from the extracted tax lines (summed), else a labeled fallback. */
    private function taxTotalField(array $taxes, array $lines, ?string $currency): ?Field
    {
        $sum = $this->sumMoney($taxes, $currency);

        if ($sum !== null) {
            return new Field($sum, self::CONF_LABELED);
        }

        return $this->moneyField($lines, $this->re(self::TAX), $currency);
    }

    /** Best-effort payment method / reference / transaction id. */
    private function extractPayment(array $lines, string $text): ?PaymentInfo
    {
        $method = match (true) {
            (bool) preg_match('/bank\s*transfer|fund\s*transfer|fpx|duitnow|ibg|rtgs|telegraphic|giro|wire|pindahan|pemindahan|银行转账|銀行轉賬|转账|轉賬|汇款|匯款/iu', $text) => 'bank_transfer',
            (bool) preg_match('/credit\s*card|debit\s*card|visa|master\s*card|amex|\bcard\b|kad\s*(?:kredit|debit)|信用卡|借记卡|借記卡|刷卡|\bcard\b/iu', $text) => 'card',
            (bool) preg_match('/e-?wallet|grab\s*pay|tng|touch\s*.?n\s*go|boost|shopee\s*pay|paypal|qr\s*pay|e-?dompet|电子钱包|電子錢包|扫码|掃碼/iu', $text) => 'e_wallet',
            (bool) preg_match('/\bcheque\b|\bcheck\b|\bcek\b|支票/iu', $text) => 'cheque',
            (bool) preg_match('/\bcash\b|tunai|现金|現金/iu', $text)          => 'cash',
            default                                                          => null,
        };

        $reference     = $this->labeledValue($lines, $this->re(self::PAY_REF));
        $transactionId = $this->labeledValue($lines, $this->re(self::TXN_ID));
        $paid          = preg_match('/\bpaid\b|payment\s*received|settled|telah\s*dibayar|已付|已收|付讫|付訖/iu', $text) ? true : null;

        if ($method === null && $reference === null && $transactionId === null && $paid === null) {
            return null;
        }

        return new PaymentInfo(
            method: $method,
            reference: $reference,
            transactionId: $transactionId,
            paid: $paid,
        );
    }

    /**
     * Extract line items from the rows above the totals block.
     *
     * Each row is split into columns (on 2+ spaces) and its cells classified as
     * code / description / quantity / price. Quantity and unit price are then
     * reconciled against the line amount (qty × unit ≈ amount) and inferred when
     * one is missing. Rows with no clear columns fall back to a regex parse.
     *
     * @return list<LineItem>
     */
    private function extractLineItems(array $lines, ?string $currency): array
    {
        $stopPat = $this->re(self::SUBTOTAL . '|' . self::TOTAL . '|' . self::BALANCE_DUE . '|' . self::AMOUNT_PAID);
        $stopAt  = count($lines);

        // Items end at the first totals line that actually carries an amount, so a
        // "Total" *column header* doesn't cut the table short.
        foreach ($lines as $i => $line) {
            if (preg_match($stopPat, $line) && $this->lastAmount($line, $currency, false) !== null) {
                $stopAt = $i;
                break;
            }
        }

        $items = [];

        for ($i = 0; $i < $stopAt; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || $this->isHeaderOrTitleRow($line)) {
                continue;
            }

            $item = $this->parseItemRow($line, FieldNormalizer::currency($line) ?? $currency);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** Column-header or document-title rows that are not themselves line items. */
    private function isHeaderOrTitleRow(string $line): bool
    {
        if (preg_match('/\b(?:invoice|receipt|invois|resit)\b|发票|發票|收据|收據/iu', $line)) {
            return true;
        }

        // A header names two or more column concepts but carries no real amount.
        $headers = preg_match_all(
            '/description|\bitem\b|\bqty\b|quantity|unit\s*price|\bprice\b|\bamount\b|\btotal\b|perihal|keterangan|butiran|kuantiti|harga|amaun|jumlah|描述|项目|項目|品名|数量|數量|单价|單價|金额|金額|摘要/iu',
            $line,
        );

        return $headers >= 2 && $this->lastAmount($line, null, false) === null;
    }

    /** Parse a single item row into a {@see LineItem}, or null when it isn't one. */
    private function parseItemRow(string $line, ?string $currency): ?LineItem
    {
        $cells = array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/u', trim($line)) ?: []),
            static fn ($c) => $c !== '',
        ));

        if (count($cells) < 2) {
            return $this->parseItemRowLoose($line, $currency);
        }

        $nums = $prices = $texts = $codes = [];

        foreach ($cells as $cell) {
            $num = $this->numericCell($cell);

            if ($num !== null) {
                $nums[] = $num;
                if ($num['priceish']) {
                    $prices[] = $num;
                }
            } elseif (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\-\/]+$/u', $cell) && preg_match('/\d/', $cell) && preg_match('/\p{L}/u', $cell)) {
                $codes[] = $cell; // e.g. "A001", "PN-12"
            } elseif (preg_match('/\p{L}{2,}/u', $cell)) {
                $texts[] = $cell;
            }
        }

        if ($nums === []) {
            return null;
        }

        [$amount, $unitPrice, $qty, $uom] = $this->assignColumns($nums, $prices, $line);

        // Description: drop a leading product code when a text cell is also present.
        $sku = null;
        if ($codes !== [] && $texts !== []) {
            $sku = $codes[0];
        } elseif ($texts === [] && $codes !== []) {
            $texts = [$codes[0]];
        }

        [$qty, $desc] = $this->qtyFromText(trim(implode(' ', $texts)), $qty);

        if (! preg_match('/\p{L}{2,}/u', $desc)) {
            return null;
        }

        [$qty, $unitPrice] = $this->reconcile($qty, $unitPrice, $amount);

        return new LineItem(
            description: $desc,
            quantity: $qty,
            unit: $uom,
            unitPrice: $unitPrice !== null ? new Money($this->fmt($unitPrice), $currency) : null,
            amount: $amount !== null ? new Money($this->fmt($amount), $currency) : null,
            sku: $sku,
            confidence: self::CONF_ITEM,
        );
    }

    /**
     * Choose amount / unit-price / quantity (+ unit) from a row's numeric cells.
     *
     * @param  list<array{raw: string, val: float, priceish: bool, unit: string|null}> $nums
     * @param  list<array{raw: string, val: float, priceish: bool, unit: string|null}> $prices
     * @return array{0: float|null, 1: float|null, 2: float|null, 3: string|null}
     */
    private function assignColumns(array $nums, array $prices, string $line): array
    {
        $amount = $unitPrice = $qty = $uom = null;

        if ($prices !== []) {
            $amount    = end($prices)['val'];
            $unitPrice = count($prices) >= 2 ? $prices[count($prices) - 2]['val'] : null;

            foreach ($nums as $n) {
                if (! $n['priceish']) {
                    $qty = $n['val'];
                    $uom = $n['unit'];
                    break;
                }
            }
        } elseif (count($nums) >= 2) {
            // No decimal/currency prices — treat trailing numbers positionally.
            $amount    = $nums[count($nums) - 1]['val'];
            $unitPrice = $nums[count($nums) - 2]['val'];
            if (count($nums) >= 3) {
                $qty = $nums[0]['val'];
                $uom = $nums[0]['unit'];
            }
        } else {
            $amount = $nums[0]['val'];
            $uom    = $nums[0]['unit'];
        }

        // Leading "N x " quantity (e.g. "2 x Widget").
        if ($qty === null && preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:x|×|\*)\s+/iu', $line, $m)) {
            $qty = (float) $m[1];
        }

        return [$amount, $unitPrice, $qty, $uom];
    }

    /**
     * Reconcile qty × unit ≈ amount, inferring whichever is missing and
     * correcting a unit price that doesn't multiply out (the line amount, being
     * right-most, is treated as authoritative).
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function reconcile(?float $qty, ?float $unitPrice, ?float $amount): array
    {
        if ($amount === null) {
            return [$qty, $unitPrice];
        }

        if ($qty !== null && $qty > 0) {
            if ($unitPrice === null || abs($qty * $unitPrice - $amount) > max(0.02, abs($amount) * 0.02)) {
                $unitPrice = $amount / $qty;
            }
        } elseif ($unitPrice !== null && $unitPrice > 0) {
            $q = $amount / $unitPrice;
            if ($q >= 1 && abs($q - round($q)) < 0.02) {
                $qty = round($q);
            }
        }

        return [$qty, $unitPrice];
    }

    /**
     * Pull an "N x" / "x N" (or bare leading) quantity out of a description.
     *
     * @return array{0: float|null, 1: string}
     */
    private function qtyFromText(string $desc, ?float $existing): array
    {
        if ($existing !== null) {
            // Strip a leading quantity token glued to the description ("2 Widget").
            return [$existing, trim(preg_replace('/^\s*\d+(?:\.\d+)?\s*(?:x|×|\*)?\s*(?=\p{L})/u', '', $desc) ?? $desc)];
        }

        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:x|×|\*)\s+(.+)$/iu', $desc, $m)) {
            return [(float) $m[1], trim($m[2])];
        }

        if (preg_match('/^(.+?)\s*(?:x|×)\s*(\d+(?:\.\d+)?)\s*$/iu', $desc, $m)) {
            return [(float) $m[2], trim($m[1])];
        }

        $stripped = trim(preg_replace('/^\s*\d+(?:\.\d+)?\s+(?=\p{L})/u', '', $desc) ?? $desc);

        return [null, $stripped === '' ? $desc : $stripped];
    }

    /** Single-column fallback: no clear column gaps, so parse by regex. */
    private function parseItemRowLoose(string $line, ?string $currency): ?LineItem
    {
        if (! preg_match_all('/' . self::MONEY_STRICT . '/u', $line, $mm) || $mm[0] === []) {
            return null;
        }

        $amounts = array_values(array_filter(array_map('trim', $mm[0]), static fn ($a) => (bool) preg_match('/\d/', $a)));
        $desc    = trim(preg_replace('/' . self::MONEY_STRICT . '/u', '', $line) ?? '');

        [$qty, $desc] = $this->qtyFromText($desc, null);

        if (! preg_match('/\p{L}{2,}/u', $desc)) {
            return null;
        }

        $amount    = FieldNormalizer::money($amounts[count($amounts) - 1], $currency);
        $unitMoney = count($amounts) > 1 ? FieldNormalizer::money($amounts[count($amounts) - 2], $currency) : null;

        [$qty, $unitPrice] = $this->reconcile($qty, $unitMoney?->toFloat(), $amount?->toFloat());

        return new LineItem(
            description: $desc,
            quantity: $qty,
            unitPrice: $unitPrice !== null ? new Money($this->fmt($unitPrice), $currency) : null,
            amount: $amount,
            confidence: self::CONF_ITEM,
        );
    }

    /**
     * Classify a cell as a number, capturing its value, whether it looks like a
     * price (decimals/currency), and any unit suffix. Null for non-numbers.
     *
     * @return array{raw: string, val: float, priceish: bool, unit: string|null}|null
     */
    private function numericCell(string $cell): ?array
    {
        $core = trim(preg_replace('/\s*(' . self::CUR . ')\s*/u', '', $cell) ?? $cell);
        $unit = null;

        if (preg_match('/^\(?-?[\d.,]+\)?$/', $core)) {
            // pure number
        } elseif (preg_match('/^(\(?-?[\d.,]+\)?)\s*(' . self::UNITS . ')$/iu', $core, $um)) {
            $core = $um[1];
            $unit = $um[2];
        } else {
            return null;
        }

        $val = FieldNormalizer::amount($core);
        if ($val === null) {
            return null;
        }

        $priceish = (bool) (preg_match('/[.,]\d{2}\)?$/', trim($cell)) || preg_match('/' . self::CUR . '/u', $cell));

        return ['raw' => trim($cell), 'val' => (float) $val, 'priceish' => $priceish, 'unit' => $unit];
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    // ---------------------------------------------------------------------
    // Parsing primitives
    // ---------------------------------------------------------------------

    /** Normalize full-width punctuation so ASCII-oriented patterns match CJK text. */
    private function normalize(string $text): string
    {
        return strtr($text, [
            '：' => ':', '，' => ',', '　' => ' ', '％' => '%',
            '（' => '(', '）' => ')', '．' => '.', '＄' => '$', '﹟' => '#', '＃' => '#',
        ]);
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(
            array_map('rtrim', preg_split('/\r\n|\r|\n/', $text) ?: []),
            static fn ($l) => trim($l) !== '',
        ));
    }

    private function nextNonEmpty(array $lines, int $i): ?string
    {
        $next = trim($lines[$i + 1] ?? '');

        return $next === '' ? null : $next;
    }

    /** Compile a raw en|ms|zh alternation into a case-insensitive Unicode regex. */
    private function re(string $raw): string
    {
        return '/' . $raw . '/iu';
    }

    /** The last monetary token on a line, as Money (amounts are usually right-aligned). */
    private function lastAmount(string $line, ?string $currency, bool $loose): ?Money
    {
        $regex = '/' . ($loose ? self::MONEY_LOOSE : self::MONEY_STRICT) . '/u';

        if (! preg_match_all($regex, $line, $mm) || $mm[0] === []) {
            return null;
        }

        $tokens = array_values(array_filter(array_map('trim', $mm[0]), static fn ($t) => preg_match('/\d/', $t)));

        return $tokens === [] ? null : FieldNormalizer::money(end($tokens), $currency);
    }

    /** Sum the amounts of several tax lines into a single Money (or null). */
    private function sumMoney(array $taxes, ?string $currency): ?Money
    {
        $sum   = 0.0;
        $found = false;

        foreach ($taxes as $tax) {
            if ($tax->amount instanceof Money && $tax->amount->isPresent()) {
                $sum     += (float) $tax->amount->amount;
                $currency = $tax->amount->currency ?? $currency;
                $found    = true;
            }
        }

        return $found ? new Money(number_format($sum, 2, '.', ''), $currency) : null;
    }

    private function dateRegex(): string
    {
        return '/('
            . '\d{4}\s*年\s*\d{1,2}\s*月\s*\d{1,2}\s*日?'
            . '|\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}'
            . '|\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}'
            . '|\d{1,2}[\s.\-]+\p{L}{3,9}\.?[\s.\-]+\d{2,4}'
            . '|\p{L}{3,9}\.?\s+\d{1,2},?\s+\d{4}'
            . ')/u';
    }

    private function isDayFirst(array $options): bool
    {
        $locale = strtolower((string) ($options['date_locale'] ?? $this->config['date_locale'] ?? 'en_MY'));

        return ! str_starts_with($locale, 'en_us');
    }
}
