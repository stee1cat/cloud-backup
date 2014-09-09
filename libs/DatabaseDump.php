<?php

    /**
     * Класс для создания дампов БД
     *
     * Class DatabaseDump
     */
    class DatabaseDump {

        private $encoding = 'UTF-8';

        /**
         * @param array $config
         */
        public function __construct(array $config) {
            $this->config = $config;
        }

        /**
         * Проверяет наличие файла дампа и его размер
         *
         * @param string $file Путь к файлу дампа
         * @return bool
         */
        public function exists($file) {
            return file_exists($file) && filesize($file);
        }

        /**
         * Архивирует указанный файл
         *
         * @param string $file Путь к файлу
         * @return bool|string Результат архивирования
         */
        public function zip($file) {
            $pathinfo = pathinfo($file);
            $archive = $pathinfo['dirname'].DIRECTORY_SEPARATOR.$pathinfo['filename'].'.zip';
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $open = $zip->open($archive, ZipArchive::CREATE);
                if ($open) {
                    $add = $zip->addFile($file, basename($file));
                    $close = $zip->close();
                    $result = ($add && $close)? $archive: false;
                }
                else {
                    $result = false;
                }
            }
            else {
                require_once Application::getBasePath('libs/vendors/PclZip.php');
                $zip = new PclZip($archive);
                $create = $zip->create($file, PCLZIP_OPT_REMOVE_PATH, $pathinfo['dirname']);
                $result = ($create)? $archive: false;
            }
            return $result;
        }

        /**
         * Создаёт дамп базы данных
         *
         * @param bool $compress Архивировать дамп
         * @return bool|string Путь к файлу, в случае ошибки false
         */
        public function create($compress = true) {
            $cmd = $this->getCommand();
            $dump = $this->getDumpPath();
            system($cmd, $result);
            if (!$result && $this->exists($dump)) {
                $result = $dump;
                if ($compress) {
                    $archive = $this->zip($dump);
                    if ($archive) {
                        unlink($dump);
                        $result = $archive;
                    }
                }
            }
            else {
                $result = '';
            }
            return $result;
        }

        /**
         * Формирует строку команды для создания бекапа
         *
         * @return mixed
         * @throws Exception
         */
        private function getCommand() {
            $cmd = $this->config['exec'];
            // User
            if (isset($this->config['user']) && mb_strlen($this->config['user'], $this->encoding) > 0) {
                $result = preg_replace('/\{user\}/iu', $this->config['user'], $cmd);
            }
            else {
                throw new Exception('Database user must not be empty!');
            }
            // Password
            if (isset($this->config['password']) && mb_strlen($this->config['password'], $this->encoding) > 0) {
                $result = preg_replace('/\{password\}/iu', $this->config['password'], $result);
            }
            else {
                $result = preg_replace('/\s+-p\{password\}/iu', '', $result);
            }
            // Database
            if (isset($this->config['database']) && mb_strlen($this->config['database'], $this->encoding) > 0) {
                $result = preg_replace('/\{database\}/iu', $this->config['database'], $result);
            }
            else {
                throw new Exception('Database name must not be empty!');
            }
            // Dump
            $dump = $this->getDumpPath();
            if (mb_strlen($dump, $this->encoding) > 0) {
                $result = preg_replace('/\{dump\}/iu', $dump, $result);
            }
            return $result;
        }

        /**
         * Формирует путь к файлу дампа
         *
         * @return string
         * @throws Exception
         */
        private function getDumpPath() {
            $result = '';
            $folder = (isset($this->config['folder']) && mb_strlen($this->config['folder'], $this->encoding) > 0)? Application::getBasePath($this->config['folder']): Application::getBasePath();
            $folder = rtrim($folder, "\/").DIRECTORY_SEPARATOR;
            if ($folder) {
                if (is_dir($folder)) {
                    $datetime = date('Ymd_His');
                    $prefix = ((isset($this->config['name']) && mb_strlen($this->config['name'], $this->encoding) > 0)? $this->config['name']: $this->config['database']);
                    if (mb_strlen($this->config['name'], $this->encoding) > 0) {
                        $result = $folder.$prefix.'_'.$datetime.'.sql';
                    }
                    else {
                        throw new Exception('Database or project name must not be empty!');
                    }
                }
                else {
                    throw new Exception('Dump folder not exists!');
                }
            }
            else {
                throw new Exception('Dump file not exists!');
            }
            return $result;
        }

    }