<?php
declare(strict_types=1);

abstract class BaseModel
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
}
