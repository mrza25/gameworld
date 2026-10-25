<?php

namespace App;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
