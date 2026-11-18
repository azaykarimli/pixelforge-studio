<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Markup
    |--------------------------------------------------------------------------
    |
    | Users pay the provider's cost plus this markup, in basis points
    | (2500 = 25%). It is copied onto each generation when it is created,
    | so changing it never rewrites the price of a generation in flight.
    |
    */

    'markup_bps' => 2500,

    /*
    |--------------------------------------------------------------------------
    | Credit Packs
    |--------------------------------------------------------------------------
    |
    | The amounts of credit a user can buy, in whole dollars. Credits are
    | simply US dollars, and buying them is simulated: no payment is taken.
    |
    */

    'credit_packs' => [5, 10, 25],

];
