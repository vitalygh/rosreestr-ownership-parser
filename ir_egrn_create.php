<?php

$config = include __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

if(isset($argv[1]) && $argv[1] == '--help'){
    print "Скрипт для создания заявок во ФГИС ЕГРН" . PHP_EOL;
    print PHP_EOL;
    print "Usage: php ir_egrn_create.php" . PHP_EOL;
    exit;
}

$dbh = getDBH($config);

$statement = $dbh->query('
    SELECT * FROM task
');
$existsPremiseTasklist = [];
while($row = $statement->fetch(PDO::FETCH_ASSOC)){
    $existsPremiseTasklist[$row['premise_id']] = true;
}

$egrn = new IR_EGRN($config);

$insertStatement = $dbh->prepare('
    INSERT INTO task(premise_id, date_added, rosreestr_id)
    VALUES (:premise_id, :date_added, :rosreestr_id)
');

$statement = $dbh->query('
    SELECT * FROM premise
    WHERE (owner_name IS NULL OR owner_name = \'\')
', PDO::FETCH_ASSOC);
//    AND ownership IS NOT NULL
//    AND ownership != \'\'

$logpath = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'requests.log';
print "Создаем заявки" . PHP_EOL;
$tasks_count = 0;
while($row = $statement->fetch(PDO::FETCH_ASSOC)){
    if(isset($existsPremiseTasklist[$row['premise_id']])){
        print "По помещению {$row['cadastral_no']} уже есть запрос, пропускаем" . PHP_EOL;
        continue;
    }
    print "Создаем запрос по помещению {$row['cadastral_no']}" . PHP_EOL;
    $id = $egrn->createRequest($row['cadastral_no']);

    $insertStatement->execute([
        ':premise_id' => $row['premise_id'],
        ':date_added' => time(),
        ':rosreestr_id' => $id,
    ]);
    $msg = date('Y.m.d H:i:s') . " По помещению {$row['cadastral_no']} создан запрос " . $id . PHP_EOL; 
    print $msg;
    file_put_contents($logpath, $msg, FILE_APPEND);
    $tasks_count += 1;
}
if ($tasks_count > 0)
	file_put_contents($logpath, PHP_EOL, FILE_APPEND);