<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();

        if ($application->make('config')->get('database.default') !== 'sqlite') {
            // RefreshDatabase menghapus seluruh schema. Guard ini harus berjalan
            // sebelum trait tersebut agar database dev tidak pernah menjadi target test.
            throw new LogicException('Automated tests must use the SQLite connection.');
        }

        return $application;
    }
}
