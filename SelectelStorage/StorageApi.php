<?php

namespace SelectelStorage;

use SelectelStorage\Container;
use SelectelStorage\Request;

class StorageApi extends Request {

    protected $token = '';
    protected $storage_url = '';

    public function __construct($user, $key, $container = NULL) {
        $header = $this
                ->curlInit([
                    'Host: auth.selcdn.ru',
                    'X-Auth-User: ' . $user,
                    'X-Auth-Key: ' . $key
                ])
                ->send()
                ->getHeaders();
        
        // Только при успешной аутентификации ответ будет 204
        if ($header['HTTP-Code'] <> 204) {
            die(($header['HTTP-Code'] == 403) ? 'Ошибка аутентификации' : 'Не удалось подключиться');
        }

        $this->setUrl($this->storage_url = $header['x-storage-url']);

        $this->token = ['X-Auth-Token: ' . $header['x-storage-token']];

        if (!is_null($container)) {
            return $this->selectContainer($container);
        }
    }

    /**
     * получение списка контейнеров
     * @param int $limit
     * @param int $marker
     * @return array
     */
    public function getContainers($limit = 100, $marker = '') {
        $content = $this->curlInit($this->token)
                ->setParams([
                    'limit' => $limit,
                    'marker' => $marker,
                    'format' => Request::FORMAT
                ])
                ->send()
                ->getContent();

        return json_decode($content, TRUE);
    }

    /**
     * выбираем экземпляр контейнера хранилища
     * @param string $name
     * @param string $cdn
     * @return object Container
     */
    public function selectContainer($name, $cdn = NULL) {
        $url = $this->getUrl() . $name;

        $headers = $this->setUrl($url)
                ->curlInit($this->token)
                ->send('HEAD')
                ->getHeaders();

        if ($headers['HTTP-Code'] <> 204) {
            die('Не удалось подключиться');
        }

        // передаем только заголовки с 'x-'
        $xheaders = [];
        foreach ($headers as $key => $value) {
            if (stripos($key, "x-") === 0)
                $xheaders[$key] = $value;
        }

        $container = new Container($name, $xheaders);
        if (!is_null($cdn)) {
            $container->setAkamaiCDN($cdn);
        }

        return $container;
    }

    /**
     * Создает новый контейнер в хранилище
     * @param string $name
     * @param string $type - тип контейнера (public, private, gallery)
     * @return Container|object
     */
    public function createContainer($name, $type = 'private') {
        $url = $this->getUrl() . $name;
        $headers = array_merge($this->token, ['X-Container-Meta-Type: ' . $type]);
        $info = $this->setUrl($url)
                ->curlInit($headers)
                ->send('PUT')
                ->getInfo();

        if (!in_array($info['http_code'], [201, 202])) {
            die('Не удалось создать контейнер');
        }

        return new Container($name, [
            'x-storage-url' => $this->storage_url, // адрес хранилища
            'x-storage-token' => $this->token ,// токен доступа
        ]);
    }

    /**
     * удаление контейнера (если он не пустой)
     * @param string $name
     * @return info
     */
    public function delete($name) {
        $url = $this->getUrl() . $name;
        $info = $this->setUrl($url)
                ->curlInit($this->token)
                ->send('DELETE')
                ->getInfo();
        if (!in_array($info['http_code'], [204, 404])) {
            echo('Не удалось удалить');
        }

        return $info;
    }

}
