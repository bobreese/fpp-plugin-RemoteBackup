<?php
// FPP's own F1-help convention (see www/config.php in FPP core) looks for
// a page-specific help/<pagename>.php per plugin page - help/config.php
// for the Config page, help/status.php for the Status page. Rather than
// duplicate or trim the full write-up into two separate pages, both just
// reuse the same comprehensive help/help.php content - one real source to
// keep up to date, not two.
require __DIR__ . '/help.php';
