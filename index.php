<?php

    $project = require_once 'configs/project.php';
    $google = require_once 'configs/google.php';
    require_once 'libs/Application.php';
    require_once 'libs/DatabaseDump.php';
    require_once 'libs/GoogleDriveBackup.php';

    $dump = new DatabaseDump($project);
    $file = $dump->create(true);

    if ($file) {
        $drive = new GoogleDriveBackup($google);
        $folder = $drive->setBackupFolder('Database dumps/'.$project['name']);
        $drive->insertFile($file, '', $folder);
//        $drive->deleteAll();
    }