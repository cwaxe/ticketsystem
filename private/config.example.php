<?php
declare(strict_types=1);

return [
    'base_url' => 'https://ticket.edv-waxenberger.de/',
    'admin_email' => 'HIER-DEINE-ADMIN-ADRESSE',
    'mail_email' => 'ticket@edv-waxenberger.de',
    'secret' => 'HIER-64-HEX-ZEICHEN',
    'imap_host' => 'imap.example.org',
    'imap_port' => 993,
    'imap_user' => 'ticket@edv-waxenberger.de',
    'imap_password' => 'HIER-EINTRAGEN',
    'smtp_host' => 'smtp.example.org',
    'smtp_port' => 465,
    'smtp_mode' => 'implicit', // implicit (465) oder starttls (typisch 587)
    'smtp_user' => 'ticket@edv-waxenberger.de',
    'smtp_password' => 'HIER-EINTRAGEN',
    // Optional, wenn der PHP-CLI-Pfad vom Webprozess aus abweicht.
    // 'update_php' => '/absoluter/pfad/zu/php',
];
