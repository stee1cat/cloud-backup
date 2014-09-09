<?php
    return array(
        'user' => '',
        'password' => '',
        'database' => '',
        'name' => '',
        'folder' => 'dumps',
        'exec' => '/usr/bin/mysqldump -e -u {user} -p{password} {database} > {dump}'
    );