alter table users
    add language enum('ru', 'en', 'ua') default 'en' not null after status;
