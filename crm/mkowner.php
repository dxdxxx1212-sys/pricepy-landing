<?php
// Создание пользователя CRM из командной строки. Пароль вводится СКРЫТО и не попадает
// в историю оболочки. Работает ТОЛЬКО из CLI (через веб отдаёт 403).
// Запуск на сервере:  php /var/www/pricepy/crm/mkowner.php
if(PHP_SAPI!=='cli'){ http_response_code(403); exit("Только из командной строки\n"); }
require __DIR__.'/lib.php';

fwrite(STDOUT,"— Создание пользователя CRM «Восток Прицеп» —\n");
fwrite(STDOUT,"Логин: ");            $login=trim(fgets(STDIN));
fwrite(STDOUT,"Имя (как показывать): "); $name=trim(fgets(STDIN)); if($name==='') $name=$login;
fwrite(STDOUT,"Роль owner/operator [owner]: "); $role=trim(fgets(STDIN)); if($role!=='operator') $role='owner';
fwrite(STDOUT,"Пароль (не отобразится): "); @shell_exec('stty -echo 2>/dev/null'); $p1=trim(fgets(STDIN)); @shell_exec('stty echo 2>/dev/null'); fwrite(STDOUT,"\n");
fwrite(STDOUT,"Повторите пароль: ");  @shell_exec('stty -echo 2>/dev/null'); $p2=trim(fgets(STDIN)); @shell_exec('stty echo 2>/dev/null'); fwrite(STDOUT,"\n");

if(mb_strlen($login)<3){ exit("✗ Логин минимум 3 символа\n"); }
if(mb_strlen($p1)<10){ exit("✗ Пароль минимум 10 символов\n"); }
if($p1!==$p2){ exit("✗ Пароли не совпадают\n"); }
try{
  crm_db()->prepare("INSERT INTO users(login,pass_hash,name,role,active,created_at) VALUES(?,?,?,?,1,?)")
    ->execute([$login, password_hash($p1,PASSWORD_DEFAULT), $name, $role, date('c')]);
  fwrite(STDOUT,"✅ Пользователь «$login» ($role) создан. Заходи: https://crm.восток-прицеп.рф/\n");
}catch(Throwable $e){ exit("✗ Ошибка (возможно, такой логин уже есть)\n"); }
