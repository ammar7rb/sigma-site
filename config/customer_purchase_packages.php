<?php

return [
    /*
    | Customer purchase packages are retained as legacy, read-only records.
    | They must not gate checkout, create new invoices, or accept new payments.
    */
    'enabled' => false,
];
