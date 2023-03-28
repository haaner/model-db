# ModelDb 

Eine PHP-Bibliothek mit der sich MySQL-Tabellen in einfacher Weise als PHP-Models handhaben lassen.

Damit ModelDb verwendet werden kann, müssen nachfolgende Definition gesetzt worden sein:
 ```
define('PROJECT_NAME', 'MyProject');
define('PROJECT_PATH', '/var/www/project/');
```
Die von ModelBase abgeleiten Models müssen sich, in Abhängigkeit von `PROJECT_NAME`, im Namespace `MyProject\Models` befinden.

Die Basis-URL und die Datenbankverbindung müssen via *.env.dist* oder *.env* definiert werden:
```
BASE_URL=//localhost/
DB_DSN=mysql:host=hhh;dbname=ddd;user=uuu;password=ppp
```