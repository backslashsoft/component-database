<?php

namespace Backslash\Database;

use Backslash\Database\Exceptions\DatabaseException;
use Backslash\Resolver\DependencyResolver;
use Business\Enums\PermissionsEnum;
use Business\Enums\UserStatusTypesEnum;
use Business\Exceptions\EnumException;
use Business\Security\Crypt;
use DateTime;
use Gdev\UserManagement\ApiControllers\RolesApiController;
use Gdev\UserManagement\ApiControllers\UsersApiController;
use Gdev\UserManagement\ApiControllers\UserStatusesApiController;
use Gdev\UserManagement\Components\UserManagementDependencyResolver;
use Gdev\UserManagement\Models\Role;
use Gdev\UserManagement\Models\User;
use Gdev\UserManagement\Models\RolePermission;
use Gdev\UserManagement\Models\UserDetail;
use Gdev\UserManagement\Models\UserRole;
use Gdev\UserManagement\Models\UserStatus;
use Spot\Config;
use Spot\Locator;
use stdClass;

class Database
{

    const ENUM_PATH = ROOT_PATH . "/Business/Enums/";
    const ENUM_NAMESPACE = "Business\Enums\\";

    private static $_resolvers = [];
    private static $_databases = null;

    public static function getInstance($name = 'default')
    {
        if (static::$_databases == null) {
            static::_construct();
        }

        return static::$_databases[$name];
    }

    public static function migrateEntities($modelsNamespaces)
    {
        unset($modelsNamespaces["MVCModel"]);

        foreach ($modelsNamespaces as $key => $modelNamespace) {

            // prepare locator
            $reflection = new \ReflectionClass($modelNamespace);
            $constants = $reflection->getStaticProperties();
            $database = (array_key_exists("database", $constants)) ? $constants["database"] : "default";
            $locator = self::getInstance($database);
            $locator->mapper($modelNamespace)->migrate();
        }
    }

    /**
     * @param DependencyResolver $resolver
     */
    public static function addResolver(DependencyResolver $resolver)
    {
        static::$_resolvers[] = $resolver;
    }

    public static function install()
    {

        $modelsNamespaces = [];
        foreach (static::$_resolvers as $resolver) {
            $modelsNamespaces = array_merge($modelsNamespaces, $resolver->Resolve());
        }

        if (static::$_databases == null) {
            static::_construct();
        }

        // install default database
        $db = static::getInstance();

        self::migrateEntities($modelsNamespaces);

        //install log database
        $logDb = static::getInstance('logserver');

        $logServerDB = new stdClass();
        $logServerDB->Locator = $logDb;
        $logServerDB->Dir = [];
        $logServerDB->Dir[] = "Data\Models\LogSession";

        // Adding lookup values
        if (!defined('self::ENUM_PATH')) {
            throw new EnumException(sprintf("Constant ENUM_PATH is not defined"));
        }
        if (!defined('self::ENUM_NAMESPACE')) {
            throw new EnumException(sprintf("Constant ENUM_NAMESPACE is not defined"));
        }
        $enumModels = self::_getEnumModels(self::ENUM_PATH);
        self::_createEnumsInDb($db, $enumModels);

        // Create admin user
        $user = UsersApiController::GetUserByUserName("backslash");
        if (false == $user) {
            $user = new User();
            $user->UserName = "backslash";
            $user->Email = "user@backslash.dev";
            $user->RegistrationDate = new DateTime();
            $user->Password = Crypt::HashPassword("123456");
            $user->Approved = true;
            $user->Active = true;
            $userId = UsersApiController::InsertUser($user);

            // add details
            $userDetails = new UserDetail();
            $userDetails->UserId = $userId;
            $userDetails->FirstName = "Michael";
            $userDetails->LastName = "James";
            $userDetails->DateOfBirth = new DateTime("30 years ago");
            UsersApiController::InsertUserDetails($userDetails);

            // add active status for admin
            $status = new UserStatus();
            $status->UserId = $userId;
            $status->UserStatusTypeId = UserStatusTypesEnum::Active;
            $status->DateFrom = new DateTime();
            $status->Message = "New Backslash User";
            UserStatusesApiController::InsertUserStatus($status);

            // add roles
            $role = new Role();
            $role->Name = "Backslash Admin";
            $role->Active = 1;
            $role->Protected = 1;
            $role->Weight = 0;
            $roleId = RolesApiController::InsertRole($role);

            // add role permissions
            $mapper = $db->mapper('Gdev\UserManagement\Models\RolePermission');
            foreach (PermissionsEnum::enum() as $key => $value) {
                $rolePermission = $mapper->where(["PermissionId" => $value, "RoleId" => $roleId])->first();
                if (false == $rolePermission) {
                    $rolePermission = new RolePermission();
                }
                $rolePermission->RoleId = $roleId;
                $rolePermission->PermissionId = $value;
                $rolePermission->Protected = true;
                $mapper->save($rolePermission);
            }
            unset($mapper);

            // add user roles
            $mapper = $db->mapper('Gdev\UserManagement\Models\UserRole');
            $userRole = $mapper->where(["UserId" => $userId, "RoleId" => $roleId])->first();
            if (false == $userRole) {
                $userRole = new UserRole();
            }
            $userRole->RoleId = $roleId;
            $userRole->UserId = $userId;
            $mapper->save($userRole);
            unset($mapper);
        }

    }

    private static function _construct()
    {

        // setting up default database
        $config = \Business\Utilities\Config\Config::GetInstance();

        $dbParameters = ["type", "dbname", "user", "pass", "host", "driver"];

        if (!isset($config->db->default)) {
            throw new DatabaseException("Default Database parameter is not defined in config file!");
        }
        $db = $config->db->default;

        foreach ($dbParameters as $dbParameter){
            if(!isset($db->$dbParameter)){
                throw new DatabaseException(sprintf("Database parameter '%s' is not defined in config file", $dbParameter));
            }
        }

        $cfg = new Config();
        $adapter = $cfg->addConnection($db->type, [
            'dbname' => $db->dbname,
            'user' => $db->user,
            'password' => $db->pass,
            'host' => $db->host,
            'driver' => $db->driver
        ]);

        /* Log SQL queries. Make sure logger is configured. */
        $logger = new \Doctrine\DBAL\Logging\DebugStack();
        $adapter->getConfiguration()->setSQLLogger($logger);

        static::$_databases['default'] = new Locator($cfg);


        // setting up logserver database
        if (!isset($config->db->logserver)) {
            throw new DatabaseException("Logserver database parameter is not defined in config file!");
        }
        $db = $config->db->logserver;

        foreach ($dbParameters as $dbParameter){
            if(!isset($db->$dbParameter)){
                throw new DatabaseException(sprintf("Logserver database parameter '%s' is not defined in config file", $dbParameter));
            }
        }

        $cfg = new Config();
        $adapter = $cfg->addConnection($db->type, [
            'dbname' => $db->dbname,
            'user' => $db->user,
            'password' => $db->pass,
            'host' => $db->host,
            'driver' => $db->driver
        ]);

        /* Log SQL queries. Make sure logger is configured. */
        $logger = new \Doctrine\DBAL\Logging\DebugStack();
        $adapter->getConfiguration()->setSQLLogger($logger);

        static::$_databases['logserver'] = new Locator($cfg);
    }

    private static function _getEnumModels($enumsPath)
    {
        $enumModels = [];
        $enumFiles = scandir($enumsPath);
        if ($enumFiles != false && count($enumFiles) > 0) {
            foreach ($enumFiles as $enumFile) {
                $file_parts = pathinfo($enumsPath . '/' . $enumFile);
                if ($file_parts['extension'] == "php") {
                    $enumName = $file_parts['filename'];
                    $enumClass = self::ENUM_NAMESPACE . $enumName;
                    $lookupEnumClass = self::ENUM_NAMESPACE . "LookupEnum";
                    if (is_subclass_of($enumClass, $lookupEnumClass)) {
                        $enumModel = call_user_func(array($enumClass, 'GetEnumModel'));
                        if ($enumModel == null) {
                            throw new EnumException(sprintf("Constant 'Model' is not defined in %s enum.", $enumName));
                        }
                        $enumModels[$enumClass] = $enumModel;
                    }
                }
            }
        }
        return $enumModels;
    }

    private static function _createEnumsInDb($db, $enumModels){
        foreach ($enumModels as $enumClass => $enumModelClass){
            $mapper = $db->mapper($enumModelClass);
            $constants = call_user_func(array($enumClass, 'enum'));
            foreach ($constants as $key => $value) {
                //createEnumInDb
                $model = $mapper->get($value);
                $model = call_user_func(array($enumClass, 'CreateModel'), $key, $value, $model);
                $mapper->save($model);
            }
            unset($mapper);
        }
    }

}