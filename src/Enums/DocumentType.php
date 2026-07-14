<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Enums;

/**
 * The kind of financial document an {@see \Raintyyek\Ocr\Documents\ExtractedDocument}
 * represents. Drives which fields are expected and how confidence is weighted.
 */
enum DocumentType: string
{
    case Invoice     = 'invoice';
    case Receipt     = 'receipt';
    case Bill        = 'bill';
    case Expense     = 'expense';
    case PaymentSlip = 'payment_slip';
    case CreditNote  = 'credit_note';
    case Unknown     = 'unknown';
}
