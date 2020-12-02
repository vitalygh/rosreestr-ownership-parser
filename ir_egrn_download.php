<?php

$config = include __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

if(isset($argv[1]) && $argv[1] == '--help'){
    print "Скрипт для загрузки результатов заданий из ФГИС ЕГРН" . PHP_EOL;
    print PHP_EOL;
    print "Usage: php ir_egrn_download.php" . PHP_EOL;
    exit;
}

$dbh = getDBH($config);

$egrn = new IR_EGRN($config);

// find request older than two days
$statement = $dbh->query('
    SELECT * FROM task, premise WHERE task.premise_id = premise.premise_id
');
$deleteStatement = $dbh->prepare('
    DELETE FROM task WHERE task_id = :task_id
');
$updateStatement = $dbh->prepare('
    UPDATE premise
    SET area = :area,
        task_rosreestr_id = :task_rosreestr_id,
        ownership = :ownership,
        owner_name = :owner_name,
        xml = :xml
    WHERE premise_id = :premise_id
');

$counters = [
    'success' => 0,
    'deleted' => 0,
    'delayed' => 0,
    'failure' => 0,
];
while($row = $statement->fetch(PDO::FETCH_ASSOC)){
    try {
        print "Проверяем результат по заявке № " . $row['rosreestr_id'] . PHP_EOL;
        $zipFile = $egrn->getResult($row['rosreestr_id']);
        if($zipFile){
            $xmlFile = $egrn->parseZipArchive($zipFile);
            $result = $egrn->parseXMLFile($xmlFile);
            $xmlpath = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . strtr($row['cadastral_no'], ":", "_") . '.xml';
            print "Сохраняем результат в " . $xmlpath . PHP_EOL;
            file_put_contents($xmlpath, $result->xml);
            $update = $updateStatement->execute([
                ':area' => $result->area,
                ':task_rosreestr_id' => $row['rosreestr_id'],
                ':ownership' => implode("\r\n", $result->ownership),
                ':owner_name' => implode("\r\n", $result->names),
                ':xml' => $result->xml,
                ':premise_id' => $row['premise_id'],
            ]);
            print "Удаляем выполненную заявку №" . $row['rosreestr_id'] . PHP_EOL;
            $detele = $deleteStatement->execute([
                ':task_id' => $row['task_id'],
            ]);
            $counters['success']++;
        }
        else{
            if($row['date_added'] + $config['rosreestr_hang_timer'] < time()) {
                print "Удаляем из ожидания заявку с истекшим сроком №" . $row['rosreestr_id'] . PHP_EOL;
                $deleteStatement->execute([
                    ':task_id' => $row['task_id'],
                ]);
                $counters['deleted']++;
            }
            else{
                $counters['delayed']++;
            }
        }
    }
    catch(Throwable $exception){
        print "Не удалось получить данные по заявке №" . $row['rosreestr_id'] . PHP_EOL;
        $counters['failure']++;
    }
}
print "Успешно " . $counters['success'] . PHP_EOL;
print "Удалено по таймауту " . $counters['deleted'] . PHP_EOL;
print "Выписка не готова " . $counters['delayed'] . PHP_EOL;
print "Ошибка обработки " . $counters['failure'] . PHP_EOL;