<?php
return [
    'environment' => 'sandbox',
    'id' => '',
    'secret' => '',
    'realm' => '',
    'callback' => 'https://aceelectric.webactiondemo.ca/billing/?a=callback',
    'admin' => '',
    'employees' => [], // Verified lowercase Intuit emails, including the authorizing administrator.
    'sales_tax_code' => '', // Approved QuickBooks tax code for items without a sales tax code.
    'card_payments' => false,
];
