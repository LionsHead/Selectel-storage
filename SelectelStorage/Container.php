<?php

namespace SelectelStorage;

use SelectelStorage\StorageApi;
use SelectelStorage\Request;

class Container extends StorageApi {

    protected $storage_url = '';
    protected $container_name = '';
    private $container = '';
    private $cdn = NULL;

    /**
     * Информация о контейнере
     * X-Container-Object-Count - количество объектов в контейнере
     * X-Container-Bytes-Used - суммарный размер объектов в контейнере
     * X-Transfered-Bytes - скачено байт из контейнера
     * X-Received-Bytes - передано байт в контейнер
     * X-Container-Meta-Type - тип контейнера (public или private)
     * X-Container-Meta-Domains - привязанные домены к контейнеру
     * X-Container-Meta-* - заголовки с произвольной пользовательской информацией
     * @var type 
     */
    private $info = [];

    /**
     * 
     * @param type $name
     * @param type $token
     * @param type $info
     */
    public function __construct($name, array $info = []) {
        $this->container_url_name = $name; 
        $this->storage_url = $info['x-storage-url']; // адрес для запросов к хранилищу
        $this->container_url = $info['x-storage-url'] . $name . '/';  // адрес  для запросов к контейнеру
        $this->token = ['X-Auth-Token: ' . $info['x-storage-token']]; // токен для авторизации
        $this->info = $info;
    }

    /**
     * получение информации о контейнере
     * @return array
     */
    public function getContainerInfo() {
        return $this->info;
    }

    /**
     * возврашает список элементов или информацию об одном элементе, limit = 1
     * @param string $path - строка, вернуть объекты в указанной папке (виртуальные папки или имя файда)
     * @param int $limit - число, ограничивает количество объектов в результате (по умолчанию у селектела 10000)
     * @param string $marker - строка, результат будет содержать объекты по значению больше указанного маркера (полезно использовать для постраничной навигации и для большого количества файлов)
     * @param string $delimiter - символ, вернуть объекты до указанного разделителя в их имени
     * @param string $prefix - строка, вернуть объекты имена которых начинаются с указанного префикса
     * @param string $delimiter - символ, вернуть объекты до указанного разделителя в их имени
     *
     * @return array содержимое контейнера или папки
     *      [downloaded] =>  сколько раз данный файл был скачан
     *      [last_modified] => время загрузки файла
     *      [hash] =>  md5 хеш
     *      [name] => имя файла
     *      [content_type] => тип файла
     *      [bytes] => размер файла
     */
    public function getFiles($path = '', $limit = 10000, $marker = NULL, $prefix = NULL, $delimiter = NULL) {

        $response = $this->setUrl($this->container_url)
                ->curlInit($this->token)
                ->setParams([
                    'path' => $path,
                    'limit' => $limit,
                    'marker' => $marker,
                    'prefix' => $prefix,
                    'delimiter ' => $delimiter,
                    'format' => Request::FORMAT
                ])
                ->send()
                ->getContent();

        $response = json_decode($response, TRUE, 128, JSON_BIGINT_AS_STRING);
        return ($limit > 1) ? $response : current($response);
    }

    /**
     * Копирование файла
     * @param string $origin оригинальный объект
     * @param string $copy новая копия
     *
     * @return array
     */
    public function copyObject($origin, $copy) {
        #$url = parse_url($this->container_url); // получение пути для нового файла
        $headers = array_merge($this->token, ['Destination: ' . parse_url($this->container_url)['path'] . $copy]);

        $info = $this->setUrl($this->container_url . $origin)
                ->curlInit($headers)
                ->send('COPY')
                ->getInfo();
        if ($info['http_code'] <> 201) {
            
            die('Не удалось скопировать');
        }
        return $info;
    }

    /**
     * создает папку в выбранном пути
     * @param string $name new dirictory name
     * @return type
     */
    public function createDirectory($name) {
        $headers = array_merge(["Content-Type: application/directory"], $this->token);
        return $this->setUrl($this->container_url . $name)
                        ->curlInit($headers)
                        ->send('PUT')
                        ->getInfo();
    }

    /**
     * отправка файла с сервера в хранилище
     * @param string $local_path - путь до локального файла
     * @param string $storage_path - путь для сохранения
     * @param array $headers - дополнительные параметры:
     *      'X-Delete-At: int', - время когда нужно удалить файл, указывается целое число в unix timestamp
     *      'X-Delete-After: int' - через какое время удалить файл, указывается в секундах
     *
     * @return array
     *   [url] - адрес файла в хранилище
     *   [cdn] - адрес в cdn
     */
    public function sendFile($local_path, $storage_path, array $headers = []) {
        // название не обязательно
        if (is_null($storage_path)) {
            $storage_path = array_pop(
                    explode(DIRECTORY_SEPARATOR, $local_path) // возьмем его из пути файла
            );
        }

        if (!file_exists($local_path)) {
            die('Не удалось найти файл: ' . $local_path);
        }

        $fp = fopen($local_path, "r");
        // добавляем токен + доп.параметры в заголовок
        $headers = array_merge($this->token, $headers);
        // соединяемся и отправляем
        $info = $this->setUrl($this->container_url . $storage_path)
                ->curlInit($headers)
                ->setFile($fp, filesize($local_path))
                ->send('PUT')
                ->getInfo();

        fclose($fp);
        /* При успешной загрузке файла будет получен HTTP ответ 201 (Created) с заголовками Content-Lenght, Content-Type и Etag.
         * В случае если при загрузке был указан заголовок ETag и проверка загруженных данных будет неудачна, то в ответ будет получен HTTP статус 422 (Unprocessable Entity).
         */
        if ($info['http_code'] !== 201) {
            die('Не удалось отправить файл');
        }

        if (!is_null($this->cdn)) {
            $info['cdn'] = $this->cdn . $storage_path;
        }
        return $info;
    }

    /**
     * сохранение файла с определенным содержимом
     * 
     * @param string $file_name - название файла в хранилище
     * @param string $content - содержимое файла
     * @param array $headers- дополнительные параметры:
     *      'X-Delete-At: int', - время когда нужно удалить файл, указывается целое число в unix timestamp
     *      'X-Delete-After: int' - через какое время удалить файл, указывается в секундах
     * @return array
     */
    public function sendFileContent($file_name, $content = NULL, array $headers = []) {
        // создаем временный файл
        $fp = fopen("php://temp", "r+");
        fputs($fp, $content);
        rewind($fp);

        // добавляем токен + доп.параметры в заголовок
        $headers = array_merge($this->token, $headers);

        // соединяемся и отправляем
        $info = $this->setUrl($this->container_url . $file_name)
                ->curlInit($headers)
                ->setFile($fp, strlen($content))
                ->send('PUT')
                ->getInfo();

        fclose($fp);

        /* При успешной загрузке файла будет получен HTTP ответ 201 (Created) с заголовками Content-Lenght, Content-Type и Etag.
         * В случае если при загрузке был указан заголовок ETag и проверка загруженных данных будет неудачна, то в ответ будет получен HTTP статус 422 (Unprocessable Entity).
         */
        if ($info['http_code'] !== 201) {
            die('Не удалось отправить файл');
        }

        if (!is_null($this->cdn)) {
            $info['cdn'] = $this->cdn . $storage_path;
        }
        return $info;
    }

    /**
     * удаление объекта из контейнера
     * @param string $name
     * @return info
     */
    public function delete($name) {
        $info = $this->setUrl($this->container_url . $name)
                ->curlInit($this->token)
                ->send('DELETE')
                ->getInfo();
        if ($info['http_code'] !== 204) {
            echo('Не удалось удалить');
        }

        return $info;
    }

    public function setAkamaiCDN($cdn = NULL) {
        $this->cdn = $cdn;
        $this->info['cdn'] = $cdn;
    }

}
