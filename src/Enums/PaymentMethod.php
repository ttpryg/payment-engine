<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Enums;

enum PaymentMethod: string
{
    case BANK_TRANSFER = 'bank_transfer';
    case VIRTUAL_ACCOUNT = 'virtual_account';
    case QRIS = 'qris';
    case E_WALLET = 'e_wallet';
    case CREDIT_CARD = 'credit_card';
    case MANUAL_TRANSFER = 'manual_transfer';
    case CASH = 'cash';
    case COD = 'cod';
}
