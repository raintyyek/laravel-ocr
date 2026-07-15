<?php

declare(strict_types=1);

/**
 * Multilingual regression corpus for the pure heuristic parser.
 *
 * Expected keys are resolved by tests/smoke.php and the PHPUnit data test.
 * Adding a real-world OCR sample here automatically contributes to the global
 * target-field accuracy score.
 *
 * @return list<array{name: string, locale: string, text: string, expected: array<string, scalar>}>
 */
return [
    [
        'name' => 'mobile e-wallet grouped labels',
        'locale' => 'en_MY',
        'text' => "12:44 PM\nPayment Result\nRM 45.00\nPaid\nMerchant\nDate & Time\nBusOnlineTicket\n26/10/2023 12:45:09\neWallet Reference\nNo.\n202310262112128001101714035177\nPayment Method\neWallet Balance\nDone",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'MYR', 'vendor' => 'BusOnlineTicket',
            'amount_paid' => '45.00', 'total' => '45.00', 'payment_date' => '2023-10-26 12:45:09',
            'payment_reference' => '202310262112128001101714035177', 'payment_method' => 'e_wallet', 'paid' => true,
        ],
    ],
    [
        'name' => 'English card payment inline labels',
        'locale' => 'en_US',
        'text' => "Payment Confirmation\nMerchant: Tech World Pte Ltd\nPayment Amount: SGD 1,299.00\nTransaction Date: 07/16/2026 09:15 PM\nReference No: SGREF-7788\nPayment Method: Visa Card\nPaid",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'SGD', 'vendor' => 'Tech World Pte Ltd',
            'amount_paid' => '1299.00', 'payment_date' => '2026-07-16 21:15:00',
            'payment_reference' => 'SGREF-7788', 'payment_method' => 'card', 'paid' => true,
        ],
    ],
    [
        'name' => 'Malay DuitNow payment',
        'locale' => 'ms_MY',
        'text' => "PEMBAYARAN BERJAYA\nPeniaga: Kedai Buku Maju\nAmaun Bayaran: RM 125,50\nTarikh Transaksi: 16/07/2026 08:05\nNo. Rujukan: MY-99118\nKaedah Pembayaran: DuitNow",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'MYR', 'vendor' => 'Kedai Buku Maju',
            'amount_paid' => '125.50', 'payment_date' => '2026-07-16 08:05:00',
            'payment_reference' => 'MY-99118', 'payment_method' => 'bank_transfer', 'paid' => true,
        ],
    ],
    [
        'name' => 'Simplified Chinese Alipay payment',
        'locale' => 'zh_CN',
        'text' => "支付成功\n商户：上海书城\n付款金额：¥88.00\n支付日期：2026年7月16日 09:30:05\n参考编号：CN20260716001\n付款方式：支付宝",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'CNY', 'vendor' => '上海书城',
            'amount_paid' => '88.00', 'payment_date' => '2026-07-16 09:30:05',
            'payment_reference' => 'CN20260716001', 'payment_method' => 'e_wallet', 'paid' => true, 'language' => 'zh',
        ],
    ],
    [
        'name' => 'Traditional Chinese bank transfer',
        'locale' => 'zh_HK',
        'text' => "轉賬成功\n商戶：香港電訊\n交易金額：HK$ 320.40\n交易日期：2026/07/16 18:20:11\n參考編號：HK-TR-66008\n付款方式：銀行轉賬",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'HKD', 'vendor' => '香港電訊',
            'amount_paid' => '320.40', 'payment_date' => '2026-07-16 18:20:11',
            'payment_reference' => 'HK-TR-66008', 'payment_method' => 'bank_transfer', 'paid' => true, 'language' => 'zh',
        ],
    ],
    [
        'name' => 'English tax invoice with item',
        'locale' => 'en_MY',
        'text' => "ACME Supplies Sdn Bhd\nTax Invoice\nInvoice No: INV-2026-088\nDate: 15/07/2026\nDue Date: 14/08/2026\nDescription  Qty  Unit Price  Amount\nPrinter Paper  2  RM 10.00  RM 20.00\nSubtotal RM 20.00\nSST 8% RM 1.60\nGrand Total RM 21.60",
        'expected' => [
            'type' => 'invoice', 'currency' => 'MYR', 'vendor' => 'ACME Supplies Sdn Bhd',
            'invoice_number' => 'INV-2026-088', 'issue_date' => '2026-07-15', 'due_date' => '2026-08-14',
            'subtotal' => '20.00', 'tax_total' => '1.60', 'total' => '21.60', 'line_items_min' => 1,
        ],
    ],
    [
        'name' => 'Malay service invoice',
        'locale' => 'ms_MY',
        'text' => "Maju Jaya Enterprise\nINVOIS CUKAI\nNo. Invois: MJ-2044\nTarikh: 16 Julai 2026\nTarikh Akhir Bayaran: 30 Julai 2026\nPerihal  Kuantiti  Harga  Jumlah\nServis penyelenggaraan  1  RM 200,00  RM 200,00\nJumlah Kecil RM 200,00\nCukai Perkhidmatan 8% RM 16,00\nJumlah Keseluruhan RM 216,00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'MYR', 'vendor' => 'Maju Jaya Enterprise', 'language' => 'ms',
            'invoice_number' => 'MJ-2044', 'issue_date' => '2026-07-16', 'due_date' => '2026-07-30',
            'subtotal' => '200.00', 'tax_total' => '16.00', 'total' => '216.00', 'line_items_min' => 1,
        ],
    ],
    [
        'name' => 'Simplified Chinese invoice',
        'locale' => 'zh_CN',
        'text' => "深圳科技有限公司\n增值税发票\n发票号码：SZ-77881\n日期：2026年7月1日\n到期日：2026年7月31日\n项目  数量  单价  金额\n技术服务  2  ¥50.00  ¥100.00\n小计 ¥100.00\n消费税 6% ¥6.00\n总计 ¥106.00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'CNY', 'vendor' => '深圳科技有限公司', 'language' => 'zh',
            'invoice_number' => 'SZ-77881', 'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
            'subtotal' => '100.00', 'tax_total' => '6.00', 'total' => '106.00', 'line_items_min' => 1,
        ],
    ],
    [
        'name' => 'Traditional Chinese cash receipt',
        'locale' => 'zh_TW',
        'text' => "台北咖啡館\n正式收據\n收據號碼：TW-9001\n日期：2026年7月16日\n總計 NT$ 180.00\n現金\n已付",
        'expected' => [
            'type' => 'receipt', 'vendor' => '台北咖啡館', 'invoice_number' => 'TW-9001',
            'issue_date' => '2026-07-16', 'total' => '180.00', 'payment_method' => 'cash', 'paid' => true, 'language' => 'zh',
        ],
    ],
    [
        'name' => 'Malay official receipt',
        'locale' => 'ms_MY',
        'text' => "Klinik Sentosa\nRESIT RASMI\nNo. Resit: RS-5510\nTarikh: 16/07/2026\nJumlah RM 75.00\nKaedah Bayaran: Tunai\nTelah Dibayar",
        'expected' => [
            'type' => 'receipt', 'currency' => 'MYR', 'vendor' => 'Klinik Sentosa', 'language' => 'ms',
            'invoice_number' => 'RS-5510', 'issue_date' => '2026-07-16', 'total' => '75.00', 'payment_method' => 'cash', 'paid' => true,
        ],
    ],
    [
        'name' => 'English utility bill',
        'locale' => 'en_MY',
        'text' => "Tenaga Utility Berhad\nElectricity Bill\nAccount No: 778899\nBill Date: 01/07/2026\nDue Date: 31/07/2026\nAmount Due RM 125.40",
        'expected' => [
            'type' => 'bill', 'currency' => 'MYR', 'vendor' => 'Tenaga Utility Berhad',
            'account_number' => '778899', 'issue_date' => '2026-07-01', 'due_date' => '2026-07-31', 'balance_due' => '125.40',
        ],
    ],
    [
        'name' => 'Malay water bill',
        'locale' => 'ms_MY',
        'text' => "Air Negeri Sdn Bhd\nBIL AIR\nNo. Akaun: 44556677\nTarikh: 02/07/2026\nTarikh Akhir Bayaran: 25/07/2026\nBaki Perlu Dibayar RM 48.90",
        'expected' => [
            'type' => 'bill', 'currency' => 'MYR', 'vendor' => 'Air Negeri Sdn Bhd', 'language' => 'ms',
            'account_number' => '44556677', 'issue_date' => '2026-07-02', 'due_date' => '2026-07-25', 'balance_due' => '48.90',
        ],
    ],
    [
        'name' => 'Simplified Chinese water bill',
        'locale' => 'zh_CN',
        'text' => "城市水务公司\n水费账单\n账户号码：CN-300022\n日期：2026年7月2日\n到期日：2026年7月28日\n应付金额：¥66.80",
        'expected' => [
            'type' => 'bill', 'currency' => 'CNY', 'vendor' => '城市水务公司', 'language' => 'zh',
            'account_number' => 'CN-300022', 'issue_date' => '2026-07-02', 'due_date' => '2026-07-28', 'total' => '66.80',
        ],
    ],
    [
        'name' => 'mixed language four-label mobile layout',
        'locale' => 'en_MY',
        'text' => "Payment Success\nMerchant\nDate & Time\nReference No.\nPayment Method\nMegaMart\n2026-07-16 14:22:11\nMIX-20260716-88\nQR Pay\nUSD 32.10\nPaid",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'USD', 'vendor' => 'MegaMart',
            'amount_paid' => '32.10', 'payment_date' => '2026-07-16 14:22:11',
            'payment_reference' => 'MIX-20260716-88', 'payment_method' => 'e_wallet', 'paid' => true,
        ],
    ],
];
