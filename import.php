<?php

$config = include __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

if(!isset($argv[1]) || $argv[1] == '--help'){
    print "Импорт данных во внутренний sqlite из csv-файла:" . PHP_EOL;
    print "csv-файл должен быть в директории runtime;" . PHP_EOL;
    print "csv-файл должен быть с разделителем запятая;" . PHP_EOL;
    print "csv-файл должен быть без строки-заголовка, только данные." . PHP_EOL;
    print PHP_EOL;
    print "Порядок колонок в файле подразумевается следующий:" . PHP_EOL;
    print "Кадастровый номер, Номер заявки, Номер собственности, ФИО, Площадь, Любые другие данные;" . PHP_EOL;
    print "Обязателен только кадастровый номер." . PHP_EOL;
    print PHP_EOL;
    print "Usage: php import.php filename.csv" . PHP_EOL;
    exit;
}

$fileImport = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $argv[1];
if(!is_readable($fileImport)){
    print "Ошибка! Файл $fileImport не доступен для чтения." . PHP_EOL;
    exit;
}

$dbh = getDBH($config);
/*
$statement = $dbh->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:table");
$statement->execute([
    ':table' => 'premise',
]);

if($statement->fetch()){
    print "Ошибка!" . PHP_EOL;
    print "В эту базу уже были импортированы данные;" . PHP_EOL;
    print "Импорт данных возможен только в пустую базу." . PHP_EOL;
    exit;
}
*/
$result = $dbh->query('create table if not exists premise
(
	premise_id integer not null
		constraint premise_pk
			primary key autoincrement,
	cadastral_no text not null,
	task_rosreestr_id text,
	area real,
	ownership text,
	owner_name text,
	extradata text,
	xml text
)');
$result = $result && $dbh->query('create table if not exists task
(
	task_id integer not null
		constraint task_pk
			primary key autoincrement,
	premise_id int not null
		constraint task_premise_premise_id_fk
			references premise,
    date_added bigint,
	rosreestr_id text
)');
$result = $result && $dbh->query('create unique index if not exists premise_cadastral_no_uindex
	on premise (cadastral_no)');
$result = $result && $dbh->query('create index if not exists task_premise_id_index
	on task (premise_id);
');
if(!$result){
    print "Ошибка! Что-то пошло совсем не так, не удалось инициализировать базу" . PHP_EOL;
    exit;
}

$totalCounter = 0;
$errorCounter = 0;

$fh = fopen($fileImport, 'rb');
while($row = fgetcsv($fh)){
    $totalCounter++;
    $statement = $dbh->prepare('
        INSERT OR REPLACE INTO premise (cadastral_no, task_rosreestr_id, ownership, owner_name, area, extradata)
        VALUES (:cadastral_no, :task_rosreestr_id, :ownership, :owner_name, :area, :extradata)
    ');
    $area = $row[3] ?? null;
    if($area){
        $area = str_replace(',', '.', $area);
    }
    $extra = [];
    if(count($row) > 4){
        $extra = array_slice($row, 4);
    }
    if(!$statement->execute([
        ':cadastral_no' => $row[0],
        ':task_rosreestr_id' => $row[1] ?? null,
        ':ownership' => $row[2] ?? null,
        ':owner_name' => $row[3] ?? null,
        ':area' => $area,
        ':extradata' => json_encode($extra),
    ])){
        $errorCounter++;
    }
}
fclose($fh);

print "Импорт данных успешно завершен;" . PHP_EOL;
print "Всего обработано $totalCounter записей;" . PHP_EOL;
if($errorCounter > 0) {
    print "Не удалось импортировать $errorCounter записей." . PHP_EOL;
}