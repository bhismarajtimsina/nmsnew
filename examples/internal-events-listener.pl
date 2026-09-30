#!/usr/bin/perl

use strict;
use warnings;
use JSON;

my $command = './bin/wca-no-pty.sh events:subscribe internal-events'; # Замените на вашу команду

# Открываем процесс
open my $pipe, '-|', $command or die "Unable to open process: $!";

# Читаем строки из потока вывода команды в цикле по одной
while (my $line = <$pipe>) {
    my $data = decode_json($line);

    print "--------------------------------------------------------------\n";
    print "Event name: $data->{name}\n";
    print "Data:\n";
    print to_json($data->{data}, {pretty => 1, utf8 => 1}) . "\n";
}

# Закрываем поток
close $pipe;