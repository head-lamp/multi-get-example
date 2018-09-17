# multiget

> php script that grabs the first 4 mebibytes of a file via curl.

## Dependencies
- PHP 7+
    - curl support

## Usage
```sh
./multiget.php -o"file" -p url
```

### get 4 mebibytes of a file sequentually no specified output file
```sh
./multiget.php https://example.com/big_file
```
and you'll have an output file called `big_file`

### get 4 mebibytes of a file in parallel no specified output file
```sh
./multiget.php -p https://example.com/big_file
```
and you'll have an output file called `big_file`

### specify output file
```sh
./multiget.php -o"my_big_file" https://example.com/big_file
```
and you'll have an output file called `my_big_file`
