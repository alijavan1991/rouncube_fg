<?php

// Test-only configuration, loaded after Roundcube's defaults by tests/bootstrap.php.
$config['db_dsnw'] = 'sqlite:///' . getenv('ZZDLP_TEST_DIR') . '/roundcube.db?mode=0600';
$config['temp_dir'] = getenv('ZZDLP_TEST_DIR') . '/temp';
$config['log_dir'] = getenv('ZZDLP_TEST_DIR') . '/logs';
$config['des_key'] = getenv('ZZDLP_TEST_DES_KEY');
$config['imap_host'] = 'localhost:143';
$config['smtp_host'] = 'localhost:587';
$config['plugins'] = ['zemzemeh_dlp'];
