<?php

namespace Backslash\Database;

interface iDatabase {

    public static function install();

    public static function migrateEntities($models);

    public static function getInstance();

}