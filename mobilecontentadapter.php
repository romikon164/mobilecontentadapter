<?php

/**
 * Конвертация версти в массив тегов без вложенности
 * 
 */
class MobileContentAdapter
{
    /**
     * Html строка
     * 
     * @var string
     */
    protected $rawContent;
    
    /**
     * Массив тегов
     * 
     * @var array
     */
    protected $content;
    
    /**
     * Базовый url для переопределения относительных ссылок
     * 
     * @var string
     */
    protected $baseUrl;
    
    /**
     * Макрос для пользовательских процессоров тегов
     * 
     * @var array<string, Closure>
     */
    protected static $tagProcessors = [];
    
    /**
     * Массив тегов у которых разрешены потомки
     * 
     * @var array<string>
     */
    protected static $nestedNodeNames = [
        'ul'  
    ];
    
    /**
     * Конструктор класса
     * 
     * @param string      $content Html строка для обработки
     * @param string|null $baseUrl Базовый url (может быть определен автоматически)
     */
    function __construct($content, $baseUrl = null)
    {
        $this->rawContent = $content;
        $this->content = [];
        
        if($baseUrl == null) {
            $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '0' || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
            $baseUrl = $scheme . trim($_SERVER['HTTP_HOST'], '/') . '/';
        } else {
            $baseUrl = rtrim($baseUrl, '/') . '/';
        }
        
        $this->baseUrl = $baseUrl;
        
        $this->processContent();
    }
    
    /**
     * Добавляет новый макрос для тега
     * 
     * @param string  $tag       
     * @param Closure $processor анонимная функция процессора
     */
    public static function addTagProcessor($tag, Closure $processor)
    {
        static::$tagProcessors[$tag] = $processor;
    }
    
    /**
     * Определяет наличие процессора для тега
     * 
     * @param  string  $tag
     * @return boolean
     */
    protected function hasTagProcessor($tag)
    {
        $tag = strtolower($tag);
        
        return array_key_exists($tag, static::$tagProcessors);
    }
    
    /**
     * Запускает пользовательский процессор тега
     * 
     * @param  string     $tag             
     * @param  DOMElement $documentElement
     */
    protected function runTagProcessor($tag, $documentElement)
    {
        $tag = strtolower($tag);
        
        if($this->hasTagProcessor($tag)) {
            static::$tagProcessors[$tag]->call($this, $documentElement);
        }
    }
    
    /**
     * Основной метод парсера
     * 
     */
    protected function processContent()
    {
        /**
         * Создаем объект DOMDocument и загружаем html строку
         */
        $domDocument = new DOMDocument();
        $domDocument->loadHTML(mb_convert_encoding($this->rawContent, 'HTML-ENTITIES', 'UTF-8'));
        
        /**
         * Ищем тег body
         */
        $documentElement = $domDocument->documentElement;
        
        while($documentElement->tagName != 'body') {
            if(!$documentElement->childNodes || $documentElement->childNodes->length == 0) {
                return ;
            }
            
            $documentElement = $documentElement->childNodes[0];
        }
        
        /**
         * Начинаем рекурсивный парсинг
         */
        $this->processDocumentElement($documentElement);
    }
    
    /**
     * Рекурсивный парсинг документа
     * 
     * @param  DOMElement  $documentElement
     */
    protected function processDocumentElement($documentElement)
    {
        if(property_exists($documentElement, 'childNodes') && $documentElement->childNodes && $documentElement->childNodes->length > 0) {
            
            // Если элемент позволяет иметь вложенность то парсим с учетом вложенности
            if(in_array($documentElement->nodeName, static::$nestedNodeNames)) {
                $this->processDocumentElementWithNestedNodes($documentElement);
                return ;
            }
            
            // Иначе парсим каждую ветку отдельно
            foreach($documentElement->childNodes as $childNode) {
                $this->processDocumentElement($childNode);
            }
            
        } else {
            // Если нет потомков то разбираем документ
            // с помощью подходящего процессора
            $this->prepareDocumentElement($documentElement);
        }
    }
    
    /**
     * Разбирает ветку со вложенностью
     * Вложенные элементы храним в виде массива
     * 
     * @param  DOMElement  $documentElement
     */
    protected function processDocumentElementWithNestedNodes($documentElement)
    {
        $content = [];
        
        foreach($documentElement->childNodes as $childNode) {
            // Добавляем только не пустые ветки
            if($childNodeContent = $this->trimContent($childNode->textContent)) {
                $content[] = $childNodeContent;
            }
        }
        
        $this->pushContent($documentElement->tagName, ['content' => $content]);
    }
    
    /**
     * Парсинг листьев html дерева
     * 
     * @param  DOMElement  $documentElement
     */
    protected function prepareDocumentElement($documentElement)
    {
        /**
         * Если элемент является обычным текстом,
         * то возвращаемся к родительскому тегу
         */
        if($documentElement->nodeName == '#text' && $documentElement->parentNode && $documentElement->parentNode->nodeName != 'body') {
            $this->prepareDocumentElement($documentElement->parentNode);
            return ;
        }
        
        // У текста могло не быть родителя, или он был тегом body
        $tag = $documentElement->nodeName == '#text' ? 'Text' : $documentElement->nodeName;
        $tag = strtolower($tag);
        
        $processMethod = 'process' . ucfirst($tag) . 'Tag';
        
        // Вызываем стандатрный процессор по возможности
        if(method_exists($this, $processMethod)) {
            $this->$processMethod($documentElement);
        // Или пользовательский
        } elseif($this->hasTagProcessor($tag)) {
            $this->runTagProcessor($tag, $documentElement);
        // На крайний случай сохраняем как текст
        } else {
            $this->processDefaultTag($documentElement);
        }
    }
    
    /**
     * Обычный текст
     * 
     * @param  DOMDocument $documentElement
     * @param  string      $type
     */
    protected function processDefaultTag($documentElement, $type = 'default')
    {
        $content = $documentElement->textContent;
        $content = $this->trimContent($content);
        
        // Не добавляем пустые ветки
        if(!empty($content)) {
            $this->pushContent($type, ['content' => $content]);
        }
    }
    
    /**
     * Ветка с избражением
     * 
     * @param  DOMDocument $documentElement
     */
    protected function processImgTag($documentElement)
    {
        $source = $this->documentElementFindAttribute($documentElement, 'src');
        
        if(!empty($source)) {
            // Обязательно сохраняем абсолютную ссылку
            $source = $this->linkPrepare($source);
            $this->pushContent('image', ['content' => $source]);
        }
    }
    
    /**
     * Стандратный процессор для ссылок
     * 
     * @param  DOMDocument $documentElement
     */
    protected function processATag($documentElement)
    {
        $url = $this->documentElementFindAttribute($documentElement, 'href');
        $content = $this->trimContent($documentElement->textContent);
        
        if(!empty($url) && !empty($content)) {
            // Только абсолютные ссылки
            $url = $this->linkPrepare($url);
            $this->pushContent('link', ['url' => $url, 'title' => $content]);
        }
    }
    
    /**
     * На всякий случай параграффы помечаем типом paragraph
     * 
     * @param  DOMDocument $documentElement
     */
    protected function processPTag($documentElement)
    {
        $this->processDefaultTag($documentElement, 'paragraph');
    }
    
    /**
     * Немного странный способ обрезать пробельные символы по краям
     * Но просто так надо =)
     * 
     * @param  string $content
     * @return string
     */
    protected function trimContent($content)
    {
        $content = html_entity_decode($content);
        
        return preg_replace('/^\s+|\s+$/um', '', $content);
    }
    
    /**
     * Вспомогательный метод для получения значения аттрибута
     * 
     * @param  DOMDocument $documentElement
     * @param  string      $attributeName
     * @return string|null
     */
    protected function documentElementFindAttribute($documentElement, $attributeName)
    {
        if(property_exists($documentElement, 'attributes') && $documentElement->attributes && $documentElement->attributes->length > 0) {
            foreach($documentElement->attributes as $attribute) {
                if($attribute->name == $attributeName) {
                    return $attribute->value;
                }
            }
        }
    }
    
    /**
     * Получаем абсолютные ссылки
     * 
     * @param  tring $link
     * @return string
     */
    protected function linkPrepare($link)
    {
        if(mb_strpos($link, 'http', 0, 'UTF-8') === 0) {
            return $link;
        } else {
            return $this->linkAddSchemeAndHostIfNeed($link);
        }
    }
    
    /**
     * Проверяем указан ли хост или ссылка относительная
     * 
     * @param  string $link
     * @return string
     */
    protected function linkAddSchemeAndHostIfNeed($link)
    {
        if(!preg_match('/^[^\/\.]+(\.[^\/\.]+)+\/.+/u', $link, $matches)) {
            return $this->baseUrl . ltrim($link, '/');   
        }
        
        return 'http://' . $link;
    }

    protected function pushContent($type, $data)
    {
        $this->content[] = array_merge(['type' => $type], $data);
    }
    
    /**
     * Получаем обработанные данные
     * 
     * @return array
     */
    public function toData()
    {
        return $this->content;
    }

    /**
     * Короткий вызов toData
     * 
     * @return array
     */
    public function data()
    {
        return $this->toData();
    }
    
    /**
     * Кодируем в json
     * 
     * @return string
     */
    public function toJson()
    {
        return @json_encode($this->content);
    }

    /**
     * Короткий вызов toJson
     * 
     * @return string
     */
    public function json()
    {
    	return $this->toJson();
    }
}