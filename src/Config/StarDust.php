<?php

namespace StarDust\Config;

use CodeIgniter\Config\BaseConfig;

class StarDust extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Users Table Configuration
     * --------------------------------------------------------------------------
     *
     * Configuration for the users table used for creator/editor/deleter relationships.
     */

    /**
     * The name of the users table.
     * Default: 'users' (CI Shield)
     *
     * @var string
     */
    public $usersTable = 'users';

    /**
     * The primary key of the users table.
     * Default: 'id' (CI Shield)
     *
     * @var string
     */
    public $usersIdColumn = 'id';

    /**
     * The column used to display the username/identifier.
     * Default: 'username' (CI Shield)
     *
     * @var string
     */
    public $usersUsernameColumn = 'username';
}
