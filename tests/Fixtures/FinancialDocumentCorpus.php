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
    [
        'name' => 'DuitNow payment history with wrapped wallet reference',
        'locale' => 'en_MY',
        'text' => "12:45'e\n← Details\n-RM11.95\nTransaction Type\nO points\nD DuitNow QR\nDut\nMerchant\nPay Via\nAA MANAGEMENT SDN\nPublic Bank Berhad DuitNow QR\nPayment Details\nDuit Now QR - AA\nMANAGEMENT SDN\nPayment Method\nDate/Time\neWallet Balance\n13/07/2026 10:36:05\n20260713w3243MY1\nWallet Ref\n71650019113088\nStatus\nSuccessful\n2026213452300QR3209\nTransaction No.\n1022\n203232043256870\nDuitNow Ref No.\n91022\nWant to earn points?\n☑\nHome\nTransfer\nActivity\nProfile",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'MYR', 'vendor' => 'AA MANAGEMENT SDN BHD',
            'amount_paid' => '11.95', 'total' => '11.95', 'payment_date' => '2026-07-13 10:36:05',
            'payment_reference' => '20260713w3243MY17165001911308', 'payment_method' => 'e_wallet', 'paid' => true,
        ],
    ],
    [
        'name' => 'English invoice with discount, shipping and three items',
        'locale' => 'en_MY',
        'text' => "Globex Trading Sdn Bhd\nTax Invoice\nInvoice No: GBX-3001\nDate: 10/07/2026\nDue Date: 09/08/2026\nDescription  Qty  Unit Price  Amount\nWidget A  2  RM 30.00  RM 60.00\nWidget B  1  RM 40.00  RM 40.00\nCable  5  RM 4.00  RM 20.00\nSubtotal RM 120.00\nDiscount RM 20.00\nShipping RM 10.00\nSST 8% RM 8.80\nGrand Total RM 118.80",
        'expected' => [
            'type' => 'invoice', 'currency' => 'MYR', 'vendor' => 'Globex Trading Sdn Bhd',
            'invoice_number' => 'GBX-3001', 'issue_date' => '2026-07-10', 'due_date' => '2026-08-09',
            'subtotal' => '120.00', 'tax_total' => '8.80', 'total' => '118.80', 'line_items_min' => 3,
        ],
    ],
    [
        'name' => 'European invoice with comma-decimal grouping (EUR)',
        'locale' => 'en_MY',
        'text' => "Muller GmbH\nRechnung / Invoice\nInvoice No: DE-9001\nDate: 05/07/2026\nSubtotal EUR 1.000,00\nVAT 19% EUR 190,00\nTotal EUR 1.190,00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'EUR', 'vendor' => 'Muller GmbH',
            'invoice_number' => 'DE-9001', 'issue_date' => '2026-07-05',
            'subtotal' => '1000.00', 'tax_total' => '190.00', 'total' => '1190.00',
        ],
    ],
    [
        'name' => 'British receipt paid by card (GBP)',
        'locale' => 'en_MY',
        'text' => "The Corner Shop\nReceipt\nReceipt No: UK-220\nDate: 16/07/2026\nTotal GBP 45.50\nPayment Method: Credit Card\nPaid",
        'expected' => [
            'type' => 'receipt', 'currency' => 'GBP', 'vendor' => 'The Corner Shop',
            'invoice_number' => 'UK-220', 'issue_date' => '2026-07-16', 'total' => '45.50',
            'payment_method' => 'card', 'paid' => true,
        ],
    ],
    [
        'name' => 'Cheque payment advice',
        'locale' => 'en_MY',
        'text' => "Payment Advice\nMerchant: Ace Hardware\nPayment Amount: RM 500.00\nTransaction Date: 12/07/2026\nReference No: CHQ-889\nPayment Method: Cheque\nPaid",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'MYR', 'vendor' => 'Ace Hardware',
            'amount_paid' => '500.00', 'payment_date' => '2026-07-12',
            'payment_reference' => 'CHQ-889', 'payment_method' => 'cheque', 'paid' => true,
        ],
    ],
    [
        'name' => 'FPX online banking payment',
        'locale' => 'en_MY',
        'text' => "Payment Successful\nMerchant: Online Store MY\nPayment Amount: RM 89.90\nTransaction Date: 14/07/2026 16:30:00\nReference No: FPX1234567\nPayment Method: FPX\nPaid",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'MYR', 'vendor' => 'Online Store MY',
            'amount_paid' => '89.90', 'payment_date' => '2026-07-14 16:30:00',
            'payment_reference' => 'FPX1234567', 'payment_method' => 'bank_transfer', 'paid' => true,
        ],
    ],
    [
        'name' => 'US month-first invoice with partial payment',
        'locale' => 'en_US',
        'text' => "Sunrise Corp\nInvoice\nInvoice No: US-500\nDate: 07/10/2026\nDue Date: 08/10/2026\nSubtotal \$1,000.00\nTax \$80.00\nTotal \$1,080.00\nAmount Paid \$500.00\nBalance Due \$580.00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'USD', 'vendor' => 'Sunrise Corp',
            'invoice_number' => 'US-500', 'issue_date' => '2026-07-10', 'due_date' => '2026-08-10',
            'subtotal' => '1000.00', 'tax_total' => '80.00', 'total' => '1080.00',
            'amount_paid' => '500.00', 'balance_due' => '580.00',
        ],
    ],
    [
        'name' => 'English credit note',
        'locale' => 'en_MY',
        'text' => "ACME Supplies Sdn Bhd\nCredit Note\nCredit Note No: CN-2026-01\nDate: 16/07/2026\nSubtotal RM 100.00\nSST 8% RM 8.00\nTotal RM 108.00",
        'expected' => [
            'type' => 'credit_note', 'currency' => 'MYR', 'vendor' => 'ACME Supplies Sdn Bhd',
            'issue_date' => '2026-07-16', 'subtotal' => '100.00', 'tax_total' => '8.00', 'total' => '108.00',
        ],
    ],
    [
        'name' => 'English expense claim',
        'locale' => 'en_MY',
        'text' => "Expense Claim\nStaff: Sarah Lim\nDate: 16/07/2026\nTotal RM 250.00",
        'expected' => [
            'type' => 'expense', 'currency' => 'MYR', 'issue_date' => '2026-07-16', 'total' => '250.00',
        ],
    ],
    [
        'name' => 'Thai QR payment slip (THB)',
        'locale' => 'en_MY',
        'text' => "Payment Successful\nMerchant: Bangkok Cafe\nPayment Amount: THB 350.00\nTransaction Date: 15/07/2026 12:00:00\nReference No: TH-778\nPayment Method: QR Pay\nPaid",
        'expected' => [
            'type' => 'payment_slip', 'currency' => 'THB', 'vendor' => 'Bangkok Cafe',
            'amount_paid' => '350.00', 'payment_date' => '2026-07-15 12:00:00',
            'payment_reference' => 'TH-778', 'payment_method' => 'e_wallet', 'paid' => true,
        ],
    ],
    [
        'name' => 'Malay invoice with discount',
        'locale' => 'ms_MY',
        'text' => "Perniagaan Setia\nINVOIS CUKAI\nNo. Invois: PS-700\nTarikh: 12/07/2026\nPerihal  Kuantiti  Harga  Jumlah\nBarang A  3  RM 20,00  RM 60,00\nJumlah Kecil RM 60,00\nDiskaun RM 10,00\nCukai Jualan 8% RM 4,00\nJumlah Keseluruhan RM 54,00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'MYR', 'vendor' => 'Perniagaan Setia', 'language' => 'ms',
            'invoice_number' => 'PS-700', 'issue_date' => '2026-07-12',
            'subtotal' => '60.00', 'tax_total' => '4.00', 'total' => '54.00', 'line_items_min' => 1,
        ],
    ],
    [
        'name' => 'Simplified Chinese cash receipt',
        'locale' => 'zh_CN',
        'text' => "北京餐厅\n收据\n收据号码：BJ-101\n日期：2026年7月16日\n总计 ¥68.00\n现金\n已付",
        'expected' => [
            'type' => 'receipt', 'currency' => 'CNY', 'vendor' => '北京餐厅', 'language' => 'zh',
            'invoice_number' => 'BJ-101', 'issue_date' => '2026-07-16', 'total' => '68.00',
            'payment_method' => 'cash', 'paid' => true,
        ],
    ],
    [
        'name' => 'English GST invoice',
        'locale' => 'en_MY',
        'text' => "Old Trade Sdn Bhd\nTax Invoice\nInvoice No: OT-01\nDate: 16/07/2026\nSubtotal RM 100.00\nGST 6% RM 6.00\nTotal RM 106.00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'MYR', 'vendor' => 'Old Trade Sdn Bhd',
            'invoice_number' => 'OT-01', 'issue_date' => '2026-07-16',
            'subtotal' => '100.00', 'tax_total' => '6.00', 'total' => '106.00',
        ],
    ],
    [
        'name' => 'Receipt with abbreviated month-name date',
        'locale' => 'en_MY',
        'text' => "Cafe Mocha\nReceipt\nReceipt No: CM-77\nDate: 16-Jul-2026\nTotal RM 18.50\nCash\nPaid",
        'expected' => [
            'type' => 'receipt', 'currency' => 'MYR', 'vendor' => 'Cafe Mocha',
            'invoice_number' => 'CM-77', 'issue_date' => '2026-07-16', 'total' => '18.50',
            'payment_method' => 'cash', 'paid' => true,
        ],
    ],
    [
        'name' => 'US invoice with long month-name date',
        'locale' => 'en_US',
        'text' => "Sunset LLC\nInvoice\nInvoice No: SL-9\nDate: July 16, 2026\nTotal \$99.00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'USD', 'vendor' => 'Sunset LLC',
            'invoice_number' => 'SL-9', 'issue_date' => '2026-07-16', 'total' => '99.00',
        ],
    ],
    [
        'name' => 'Receipt with currency code suffix',
        'locale' => 'en_MY',
        'text' => "Kedai Test\nReceipt\nReceipt No: KT-1\nDate: 16/07/2026\nTotal 45.50 MYR\nCash",
        'expected' => [
            'type' => 'receipt', 'currency' => 'MYR', 'vendor' => 'Kedai Test',
            'invoice_number' => 'KT-1', 'issue_date' => '2026-07-16', 'total' => '45.50',
            'payment_method' => 'cash',
        ],
    ],
    [
        'name' => 'Receipt with rounding and change lines',
        'locale' => 'en_MY',
        'text' => "Mini Mart\nReceipt\nReceipt No: MM-5\nDate: 16/07/2026\nSubtotal RM 9.85\nRounding RM 0.05\nTotal RM 9.90\nCash RM 10.00\nChange RM 0.10",
        'expected' => [
            'type' => 'receipt', 'currency' => 'MYR', 'vendor' => 'Mini Mart',
            'invoice_number' => 'MM-5', 'issue_date' => '2026-07-16',
            'subtotal' => '9.85', 'total' => '9.90', 'payment_method' => 'cash',
        ],
    ],
    [
        'name' => 'Invoice with large thousands-separated amounts',
        'locale' => 'en_MY',
        'text' => "Big Corp Sdn Bhd\nTax Invoice\nInvoice No: BC-1\nDate: 16/07/2026\nSubtotal RM 1,234,567.89\nSST 8% RM 98,765.43\nTotal RM 1,333,333.32",
        'expected' => [
            'type' => 'invoice', 'currency' => 'MYR', 'vendor' => 'Big Corp Sdn Bhd',
            'invoice_number' => 'BC-1', 'issue_date' => '2026-07-16',
            'subtotal' => '1234567.89', 'tax_total' => '98765.43', 'total' => '1333333.32',
        ],
    ],
    [
        'name' => 'Traditional Chinese invoice with business tax',
        'locale' => 'zh_TW',
        'text' => "台灣科技股份有限公司\n統一發票\n發票號碼：TW-5001\n日期：2026年7月16日\n小計 NT\$ 500.00\n營業稅 5% NT\$ 25.00\n總計 NT\$ 525.00",
        'expected' => [
            'type' => 'invoice', 'currency' => 'TWD', 'vendor' => '台灣科技股份有限公司', 'language' => 'zh',
            'invoice_number' => 'TW-5001', 'issue_date' => '2026-07-16',
            'subtotal' => '500.00', 'tax_total' => '25.00', 'total' => '525.00',
        ],
    ],
];
