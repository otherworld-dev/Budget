<?php

declare(strict_types=1);

/**
 * Minimal Doctrine DBAL stubs for unit testing.
 *
 * The OCP interfaces (IQueryBuilder, IExpressionBuilder, etc.) reference
 * Doctrine DBAL classes that live in the Nextcloud server, not in app
 * dependencies. These stubs provide just enough for the interfaces to load
 * and their constants to resolve in PHPUnit tests.
 */

namespace Doctrine\DBAL;

if (!class_exists(ParameterType::class)) {
    class ParameterType {
        public const NULL = 0;
        public const INTEGER = 1;
        public const STRING = 2;
        public const LARGE_OBJECT = 3;
        public const BOOLEAN = 5;
        public const BINARY = 16;
        public const ASCII = 17;
    }
}

if (!class_exists(ArrayParameterType::class)) {
    class ArrayParameterType {
        public const INTEGER = 101;
        public const STRING = 102;
        public const ASCII = 117;
        public const BINARY = 116;
    }
}

if (!class_exists(Connection::class)) {
    class Connection {
    }
}

if (!class_exists(Exception::class)) {
    class Exception extends \Exception {
    }
}

namespace Doctrine\DBAL\Types;

if (!class_exists(Types::class)) {
    class Types {
        public const BOOLEAN = 'boolean';
        public const INTEGER = 'integer';
        public const STRING = 'string';
        public const TEXT = 'text';
        public const DATETIME_MUTABLE = 'datetime';
        public const DATE_MUTABLE = 'date';
        public const FLOAT = 'float';
        public const JSON = 'json';
        public const BIGINT = 'bigint';
        public const SMALLINT = 'smallint';
    }
}

if (!class_exists(Type::class)) {
    class Type {
    }
}

namespace Doctrine\DBAL\Query\Expression;

if (!class_exists(ExpressionBuilder::class)) {
    class ExpressionBuilder {
        public const EQ = '=';
        public const NEQ = '<>';
        public const LT = '<';
        public const LTE = '<=';
        public const GT = '>';
        public const GTE = '>=';
    }
}

namespace Doctrine\DBAL\Schema;

if (!class_exists(Schema::class)) {
    class Schema {
    }
}

namespace Doctrine\DBAL\Platforms;

if (!class_exists(AbstractPlatform::class)) {
    abstract class AbstractPlatform {
    }
}

namespace Doctrine\DBAL\Logging;

if (!interface_exists(SQLLogger::class)) {
    interface SQLLogger {
    }
}
