# Mobile content adapter

Convert Html strings to JSON

## Getting Started

Clone repository and require file

### Prerequisites

Print site content on mobile application

## Uses

```
$json = (new MobileContentAdaptor('<h1>Header</h1><p><img src="..."><a href="...">link</a>'))->toJson();

```

### Custom tag process

```
MobileContentAdaptor::addTagProcessor('my-tag', function ($documentElement) {
  if($content = $this->trimContent($documentElement->textContent)) {
    $this->pushContent('my-tag', ['content' => $content]);
  }
});

$json = (new MobileContentAdaptor('<my-tag>...</my-tag>'))->toJson();

```
## Authors

* **Roman Byzov** - [PurpleBooth](https://github.com/romikon164)
