<?php

    // for php version <= 5.2.0
    if (!function_exists('sys_get_temp_dir')) {
        function sys_get_temp_dir() {
            if ($temp = getenv('TMP')) {
                return $temp;
            }
            if ($temp = getenv('TEMP')) {
                return $temp;
            }
            if ($temp = getenv('TMPDIR')) {
                return $temp;
            }
            $temp = tempnam(__FILE__, '');
            if (file_exists($temp)) {
                unlink($temp);
                return dirname($temp);
            }
            return null;
        }
    }

    // for php version < 5.2.0
    if (!function_exists('json_encode')) {

        require_once Application::getBasePath('libs/vendors/JSON.php');

        function json_encode($arg) {
            global $services_json;
            if (!isset($services_json)) {
                $services_json = new Services_JSON();
            }
            return $services_json->encode($arg);
        }

        function json_decode($arg) {
            global $services_json;
            if (!isset($services_json)) {
                $services_json = new Services_JSON();
            }
            return $services_json->decode($arg);
        }

    }

    class Application {

        /**
         * Возвращает абсолютый путь к директории размещения скрипта бекапа
         *
         * @param string $path
         * @return string
         */
        public static function getBasePath($path = '') {
            $result = dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR;
            $path = trim($path);
            if ($path) {
                $path = ltrim($path, '\/');
                $result .= $path;
            }
            return $result;
        }

    }