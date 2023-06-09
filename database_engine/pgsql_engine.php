<?php

include_once("engine.php");
include_once("pdo_engine.php");

class PgEngCommandImp extends EngCommandImp {

    public function GetCastToCharExpression($value, $fieldInfo) {
        return sprintf("CAST(%s AS VARCHAR)", $value);
    }

    protected function CreateCaseSensitiveLikeExpression($left, $right) {
        return sprintf('%s LIKE %s', $left, $right);
    }

    public function QuoteIdentifier($identifier) {
        return '"' . $identifier . '"';
    }

    public function EscapeString($string) {
        if ($this->GetConnectionFactory()->GetMasterConnection()) {
            return $this->GetConnectionFactory()->GetMasterConnection()->getEscapedString($string);
        }
        else {
            return $string;
            // return pg_escape_string($string);
        }
    }

    protected function GetBlobFieldValueAsSQL($value) {
        if ($this->GetConnectionFactory()->GetMasterConnection()) {
            return $this->GetConnectionFactory()->GetMasterConnection()->getQuotedString($value);
        }
        else {
            if (is_array($value)) {
                return '\'' . pg_escape_bytea(file_get_contents($value[0])) . '\'';
            } else {
                return '\'' . pg_escape_bytea($value) . '\'';
            }
        }
    }

    protected function getBooleanFalseAsSQL() {
        return 'false';
    }

    protected function getBooleanTrueAsSQL() {
        return 'true';
    }

    public static function RetrieveServerVersion(EngConnection $connection) {
        try {
            $versionString = $connection->ExecScalarSQL('SELECT version()');
            if ((preg_match("/(\d+)\.(\d+)/", $versionString, $matches)) && (count($matches) == 3)) {
                $connection->GetServerVersion()->SetMajor($matches[1]);
                $connection->GetServerVersion()->SetMinor($matches[2]);
            }
        } catch (Exception $e) {
        }
    }

    /** @inheritdoc */
    public function getSelectSQLWithLimitation($selectSQL, $limitNumber, $limitOffset) {
        return $selectSQL . ' ' . sprintf('LIMIT %d OFFSET %d', $limitNumber, $limitOffset);
    }

}

class PgConnectionFactory extends ConnectionFactory {
    public function DoCreateConnection($connectionParams) {
        return new PgConnection($connectionParams);
    }

    public function CreateDataReader(IEngConnection $connection, $sql) {
        return new PgDataReader($connection, $sql);
    }

    public function CreateEngCommandImp() {
        return new PgEngCommandImp($this);
    }

}

class PgPDOConnectionFactory extends ConnectionFactory {
    public function DoCreateConnection($connectionParams) {
        return new PgPDOConnection($connectionParams);
    }

    public function CreateDataReader(IEngConnection $connection, $sql) {
        return new PgPDODataReader($connection, $sql);
    }

    public function CreateEngCommandImp() {
        return new PgEngCommandImp($this);
    }

}

class PgConnection extends EngConnection {
    private $connectionHandle;
    private $connectionError;

    public function ConnectionErrorHandler($errno, $errstr, $errfile, $errline) {
        $errorResult = explode(':', $errstr, 2);
        $this->connectionError = $errorResult[1];
    }

    protected function DoConnect() {
        set_error_handler(array($this, 'ConnectionErrorHandler'));
        $this->connectionHandle = @pg_connect(
            "host=" . $this->ConnectionParam('server') . ' ' .
            "dbname=" . $this->ConnectionParam('database') . ' ' .
            "port=" . $this->ConnectionParam('port') . ' ' .
            "user=" . $this->ConnectionParam('username') . ' ' .
            "password=" . $this->ConnectionParam('password')
        );
        restore_error_handler();
        if (!$this->connectionHandle)
            return false;
        if ($this->ConnectionParam('client_encoding') != '')
            $this->ExecSQL('SET CLIENT_ENCODING TO \'' . $this->ConnectionParam('client_encoding') . '\'');
        $this->ExecSQL('SET datestyle = ISO');
        PgEngCommandImp::RetrieveServerVersion($this);
        return true;
    }

    protected function DoCreateDataReader($sql) {
        return new PgDataReader($this, $sql);
    }

    public function IsDriverSupported() {
        return function_exists('pg_connect');
    }

    protected function DoGetDBMSName() {
        return 'PostgreSQL';
    }

    protected function DoGetDriverExtensionName() {
        return 'pgsql';
    }

    protected function DoGetDriverInstallationLink() {
        return 'http://www.php.net/manual/en/pgsql.installation.php';
    }

    protected function DoDisconnect() {
        @pg_close($this->connectionHandle);
    }

    public function __construct($connectionParams) {
        parent::__construct($connectionParams);
    }

    public function GetConnectionHandle() {
        return $this->connectionHandle;
    }

    protected function DoExecSQL($sql) {
        return @pg_query($this->GetConnectionHandle(), $sql) ? true : false;
    }

    protected function doExecScalarSQL($sql) {
        if ($queryHandle = @pg_query($this->GetConnectionHandle(), $sql)) {
            $queryResult = @pg_fetch_array($queryHandle, null, PGSQL_NUM);
            return $queryResult[0];
        }
        return false;
    }

    public function DoLastError() {
        if ($this->connectionHandle)
            return pg_last_error($this->connectionHandle);
        else
            return $this->connectionError;
    }

    public function SupportsLastInsertId() {
        return $this->IsLastValSupported();
    }

    public function GetLastInsertId() {
        if ($this->IsLastValSupported()) {
            try {
                $result = $this->ExecScalarSQL("SELECT lastval()");
            }
            catch (Exception $e) {
                return null;
            }
            return $result;
        }
        return null;
    }

    private function IsLastValSupported() {
        return $this->GetServerVersion()->IsServerVersion(8, 1);
    }

    protected function doGetQuotedString($value) {
        return  '\'' . pg_escape_bytea($this->connectionHandle, $value) . '\'';
    }

    public function getEscapedString($value) {
        return pg_escape_string($this->connectionHandle, $value);
    }
}

class PgDataReader extends EngDataReader {
    private $queryResult;
    private $lastFetchedRow;
    /**
     * @var PgConnection
     */
    private $pgConnection;

    protected function FetchField() {
        throw new LogicException('PgDataReader does not support method `FetchField`');
    }

    protected function FetchFields() {
        for ($i = 0; $i < pg_num_fields($this->queryResult); $i++)
            $this->AddField(pg_field_name($this->queryResult, $i));
    }

    protected function DoOpen() {
        $this->queryResult = @pg_query($this->pgConnection->GetConnectionHandle(), $this->GetSQL());
        if ($this->queryResult)
            return true;
        else
            return false;
    }

    public function __construct($connection, $sql) {
        parent::__construct($connection, $sql);
        $this->queryResult = null;
        $this->pgConnection = $connection;
    }

    public function Opened() {
        return $this->queryResult ? true : false;
    }

    public function Seek($ARowIndex) {
        throw new LogicException('PgDataReader does not support method `Seek`');
    }

    public function Next() {
        $this->lastFetchedRow = pg_fetch_array($this->queryResult);
        return $this->lastFetchedRow ? true : false;
    }

    public function GetActualFieldValue(&$fieldName, $value) {
        $fieldInfo = $this->GetFieldInfoByFieldName($fieldName);
        if (!isset($fieldInfo))
            return parent::GetActualFieldValue($fieldName, $value);
        else if ($fieldInfo->FieldType == ftBoolean)
            return ($value == 't') or ($value == '1');
        else
            return parent::GetActualFieldValue($fieldName, $value);
    }

    public function GetFieldValueByName($AFieldName) {
        if ($this->lastFetchedRow) {
            if (pg_field_type($this->queryResult, $this->GetFieldIndexByName($AFieldName)) == 'bytea')
                return pg_unescape_bytea((string) $this->lastFetchedRow[$AFieldName]);
            else
                return $this->GetActualFieldValue($AFieldName, $this->lastFetchedRow[$AFieldName]);
        } else {
            return null;
        }
    }

}

class PgPDOConnection extends PDOConnection {
    protected function CreatePDOConnection() {
        return new PDO(
            sprintf('pgsql:host=%s port=%s dbname=%s',
                $this->ConnectionParam('server'),
                $this->ConnectionParam('port'),
                $this->ConnectionParam('database')),
            $this->ConnectionParam('username'),
            $this->ConnectionParam('password'));
    }

    protected function DoGetDBMSName() {
        return 'PostgreSQL';
    }

    protected function DoGetDriverExtensionName() {
        return 'pdo_pgsql';
    }

    protected function DoGetDriverInstallationLink() {
        return 'http://php.net/manual/en/ref.pdo-pgsql.php';
    }

    protected function DoAfterConnect() {
        if ($this->ConnectionParam('client_encoding') != '')
            $this->ExecSQL('SET CLIENT_ENCODING TO \'' . $this->ConnectionParam('client_encoding') . '\'');
        $this->ExecSQL('SET datestyle = ISO');
        PgEngCommandImp::RetrieveServerVersion($this);
    }

    protected function DoCreateDataReader($sql) {
        return new PgPDODataReader($this, $sql);
    }

    public function SupportsLastInsertId() {
        return $this->IsLastValSupported();
    }

    public function GetLastInsertId() {
        if ($this->IsLastValSupported()) {
            try {
                $result = $this->ExecScalarSQL("SELECT lastval()");
            }
            catch (Exception $e) {
                return null;
            }
            return $result;
        }
        return null;
    }

    private function IsLastValSupported() {
        return $this->GetServerVersion()->IsServerVersion(8, 1);
    }

    protected function doGetQuotedString($value) {
        if (version_compare(PHP_VERSION, "5.5.0", ">=")) {
            return $this->GetConnectionHandle()->quote($value, PDO::PARAM_LOB);
        }
        else {
            // return $this->GetConnectionHandle()->quote($value);
            return  '\'' . pg_escape_bytea($value) . '\'';
        }
    }

    public function getEscapedString($value) {
        if (version_compare(PHP_VERSION, "5.5.0", ">=")) {
            return substr($this->GetConnectionHandle()->quote($value), 1, -1);
        }
        else {
            return  pg_escape_string($value);
        }

    }
}

class PgPDODataReader extends PDODataReader {
    function __construct($connection, $sql) {
        parent::__construct($connection, $sql);
    }

    function GetActualFieldValue(&$fieldName, $value) {
        $fieldInfo = $this->GetFieldInfoByFieldName($fieldName);
        if (!isset($fieldInfo))
            return parent::GetActualFieldValue($fieldName, $value);
        else if ($fieldInfo->FieldType == ftBoolean)
            return ($value == 't') or ($value == '1');
        else
            return parent::GetActualFieldValue($fieldName, $value);
    }

    function DoTransformFetchedValue($fieldName, &$fetchedValue) {
        if ($this->GetColumnNativeType($fieldName) == 'bytea') {
            if (($fetchedValue == null) || (!isset($fetchedValue)))
                return null;
            else
                return stream_get_contents($fetchedValue);
        } else
            return parent::DoTransformFetchedValue($fieldName, $fetchedValue);
    }
}
