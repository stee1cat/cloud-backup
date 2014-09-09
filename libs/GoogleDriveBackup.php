<?php

    /**
     * Класс для работы с Google Drive API
     *
     * Class GoogleDriveBackup
     */
    class GoogleDriveBackup {

        /**
         * @var Google_Client
         */
        private $client;

        /**
         * @var Google_Service_Drive
         */
        private $drive;

        /**
         * @var array
         */
        private $config;

        /**
         * MIME-тип для директории
         *
         * @var string
         */
        private $mimeFolder = 'application/vnd.google-apps.folder';

        /**
         * Директория для бекапа в Google Drive
         *
         * @var Google_Service_Drive_ParentReference
         */
        private $backupFolder = '';

        /**
         * Размер чанка при передачи файла
         *
         * @var integer
         */
        private $chunkSize = 1048576;

        /**
         * @param array $config Конифигурация
         */
        public function __construct($config = array()) {
            $this->init();
            $this->config = $config;
            if ($this->config) {
                $this->client = new Google_Client();
                $this->client->setClientId($this->config['client_id']);
                $this->client->setApplicationName('Google BackUp');
                $key = file_get_contents($this->config['key_file']);
                $credentials = new Google_Auth_AssertionCredentials($this->config['email'], $this->config['scopes'], $key);
                $this->client->setAssertionCredentials($credentials);
                $this->drive = new Google_Service_Drive($this->client);
            }
        }

        /**
         * Создаёт папку
         *
         * @param string $name Имя директории
         * @param Google_Service_Drive_ParentReference $parent Родительская директория
         * @return bool
         */
        public function createFolder($name, Google_Service_Drive_ParentReference $parent = null) {
            $result = false;
            if ($name) {
                $file = $this->createFile($name, $this->mimeFolder, '', $parent);
                $createdFile = $this->drive->files->insert($file, array(
                    'data' => '',
                    'mimeType' => $this->mimeFolder
                ));
                $result = $createdFile['id'];
                $this->setPermissions($createdFile['id'], $this->config['share_to']);
            }
            return $result;
        }

        /**
         * Загружает файл в Google Drive
         *
         * @param string $path Путь к файлу
         * @param string $description Описание файла
         * @param Google_Service_Drive_ParentReference $parent Родительская директория
         * @return bool
         */
        public function insertFile($path, $description = '', Google_Service_Drive_ParentReference $parent = null) {
            $result = false;
            if (file_exists($path)) {
                $mime = mime_content_type($path);
                $file = $this->createFile(basename($path), $mime, $description, $parent);
                $createdFile = $this->drive->files->insert($file, array(
                    'uploadType' => 'multipart',
                    'data' => file_get_contents($path),
                    'mimeType' => $mime,
                ));
                $result = $createdFile['id'];
                $this->setPermissions($createdFile['id'], $this->config['share_to']);
            }
            return $result;
        }

        public function insertResumableFile($path, $description = '', Google_Service_Drive_ParentReference $parent = null) {
            $result = false;
            if (file_exists($path)) {
                $fileInfo = $this->getFileInfo($path);
                $file = $this->createFile($fileInfo['basename'], $fileInfo['mime'], $description, $parent);
                $this->client->setDefer(true);
                $request = $this->drive->files->insert($file);
                $media = new Google_Http_MediaFileUpload($this->client, $request, $fileInfo['mime'], null, true, $this->chunkSize);
                $media->setFileSize($fileInfo['filesize']);
                $status = false;
                $handle = fopen($path, 'rb');
                while (!$status && !feof($handle)) {
                    $chunk = fread($handle, $this->chunkSize);
                    $status = $media->nextChunk($chunk);
                }
                fclose($handle);
                $this->client->setDefer(false);
                if ($status) {
                    $result = $status['id'];
                    echo "<pre>", print_r($status, 1), "</pre>";
                    exit;
                    $this->setPermissions($status['id'], $this->config['share_to']);
                }
            }
            else {
                throw new Exception('File `'.$path.'`not exists!');
            }
            return $result;
        }

        /**
         * Возвращает список всех файлов
         *
         * @return Google_Service_Drive_DriveFile[]
         */
        public function listAllFiles() {
            $result = array();
            $pageToken = null;
            do {
                try {
                    $params = array();
                    if ($pageToken) {
                        $params['pageToken'] = $pageToken;
                    }
                    $files = $this->drive->files->listFiles($params);
                    $result = array_merge($result, $files->getItems());
                    $pageToken = $files->getNextPageToken();
                }
                catch (Exception $e) {
                    echo $e->getMessage();
                    $pageToken = null;
                }
            }
            while ($pageToken);
            return $result;
        }

        /**
         * Удаляет все файлы (и папки) из Google Drive
         */
        public function deleteAll() {
            $files = $this->listAllFiles();
            try {
                foreach ($files as $file) {
                    $this->drive->files->delete($file['id']);
                }
            }
            catch (Exception $e) {
                echo $e->getMessage();
            }
        }

        /**
         * Устанавливает родительскую директорию для бекапа, при необходимости их создаёт
         *
         * @param string $name Путь к папке на Google Drive
         * @return bool|Google_Service_Drive_ParentReference
         */
        public function setBackupFolder($name) {
            $parent = $this->createParentReference($this->getRootFolderID());
            if (is_string($name)) {
                $paths = $this->filterPath(explode('/', $name));
                foreach ($paths as $path) {
                    $folderID = $this->findFileIdByName($path, $parent);
                    // Не нашли, создаём
                    if (!$folderID) {
                        $folderID = $this->createFolder($path, $parent);
                    }
                    $parent = $this->createParentReference($folderID);
                }
            }
            $this->backupFolder = $parent;
            return $parent;
        }

        /**
         * Ищёт файл по его имени и родительской папке
         *
         * @todo Поиск в родительской папке можно упростить, получив сразу список файлов этой директории
         *
         * @param string $name Имя файла
         * @param Google_Service_Drive_ParentReference $parent Родительская директория
         * @return bool
         */
        public function findFileIdByName($name, Google_Service_Drive_ParentReference $parent = null) {
            $result = false;
            $files = $this->listAllFiles();
            foreach ($files as $file) {
                $nameEquals = ($file['title'] == $name);
                if (!$parent) {
                    if ($nameEquals) {
                        $result = $file['id'];
                        break;
                    }
                }
                else {
                    if ($nameEquals) {
                        $parents = $file->getParents();
                        foreach ($parents as $item) {
                            if ($item['id'] == $parent->getId()) {
                                $result = $file['id'];
                                break 2;
                            }
                        }
                    }
                }
            }
            return $result;
        }

        /**
         * Инициализация
         */
        private function init() {
            set_include_path(Application::getBasePath("libs/vendors/").PATH_SEPARATOR.get_include_path());
            require_once 'Google/Client.php';
            require_once 'Google/Http/MediaFileUpload.php';
            require_once 'Google/Service/Drive.php';
        }

        /**
         * Создаёт ссылку на родительскую папку
         *
         * @param string $parentID ID родительской папки
         * @return bool|Google_Service_Drive_ParentReference
         */
        private function createParentReference($parentID) {
            $result = false;
            if ($parentID) {
                $reference = new Google_Service_Drive_ParentReference();
                $reference->setId($parentID);
                $result = $reference;
            }
            return $result;
        }

        /**
         * Создаёт файл в Google Drive
         *
         * @param string $name Имя файла
         * @param string $mime MIME-тип файла
         * @param string $description Описание
         * @param Google_Service_Drive_ParentReference $parent Родительская директория
         * @return Google_Service_Drive_DriveFile
         */
        private function createFile($name, $mime, $description = '', Google_Service_Drive_ParentReference $parent = null) {
            $file = new Google_Service_Drive_DriveFile();
            $file->setTitle($name);
            $file->setDescription($description);
            $file->setMimeType($mime);
            if ($parent) {
                $file->setParents(array($parent));
            }
            return $file;
        }

        /**
         * Устанавливает права на файл, по сути расшаривает его для указанных пользователей
         *
         * @param string $fileID ID файла
         * @param string|array $shareTo Список пользователей
         */
        private function setPermissions($fileID, $shareTo) {
            if ($shareTo) {
                if (is_string($shareTo)) {
                    $shareTo = array($shareTo);
                }
                if (is_array($shareTo)) {
                    foreach ($shareTo as $email) {
                        if (is_string($email)) {
                            $permissions = new Google_Service_Drive_Permission();
                            $permissions->setValue($email);
                            $permissions->setType('user');
                            $permissions->setRole('reader');
                            $this->drive->permissions->insert($fileID, $permissions);
                        }
                    }
                }
            }
        }

        /**
         * Фильтрует сегменты пути к папке бекапа
         *
         * @param array $paths
         * @return array
         */
        private function filterPath($paths) {
            $result = array();
            if (is_array($paths)) {
                foreach ($paths as $path) {
                    if (!preg_match('/^\s*$/iu', $path)) {
                        $result[] = $path;
                    }
                }
            }
            return $result;
        }

        /**
         * Возвращает ID корневой директории
         *
         * @return mixed
         */
        private function getRootFolderID() {
            $about = $this->drive->about->get();
            return $about->getRootFolderId();
        }

        /**
         * Возвращает информацию о файле
         *
         * @param string $path Путь к файлу
         * @return bool|array
         */
        private function getFileInfo($path) {
            $result = false;
            if (file_exists($path)) {
                $result = array(
                    'filename' => basename($path),
                    'mime' => mime_content_type($path),
                    'filesize' => filesize($path)
                );
            }
            return $result;
        }

    }