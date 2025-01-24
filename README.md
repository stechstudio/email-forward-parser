# Email Forward Parser

This is a PHP port of [email-forward-parser](https://github.com/crisp-oss/email-forward-parser) by [Crisp OSS](https://github.com/crisp-oss).

All credit goes to them.

## Installation

```bash
composer require stechstudio/email-forward-parser
```

## Usage

```php  
use STS\EmailForward\Parser;

$parser = new Parser();

$result = $parser->read($emailBody, $emailSubject);

echo $result['forwarded']; // true
echo $result['email']['from']['address']; // john.doe@acme.com
```

See https://github.com/crisp-oss/email-forward-parser/blob/master/README.md for more usage examples.

## License

MIT