<?php
// Пример настроек обработчика заявок. Настоящий файл: ~/shtorme-private/config.php на хостинге (вне папки сайта),
// создаётся при выкладке; токен бота в репозиторий не попадает.
return [
    'tg_token' => '123456:ABC...',            // токен бота @shtorme_zayavki_bot
    'tg_chat' => '-5566105368',                // группа для заявок
    'telegram_mode' => 'full',                 // full: заявка целиком; signal: только сигнал без контактов
    'mail_to' => 'shtor.me@yandex.ru',
    'mail_from' => 'noreply@shtorme.ru',
    'salt' => 'случайная строка',              // для хеша IP в защите от частых отправок
    'origins' => ['shtorme.ru', 'www.shtorme.ru', 'shtorme.online', 'www.shtorme.online'],
];
