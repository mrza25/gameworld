<?php

namespace App;

enum PaymentMethod: string
{
    case Gateway = 'gateway';
    case Wallet = 'wallet';
    case CardToCard = 'card_to_card';
}
