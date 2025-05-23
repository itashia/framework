<?php

namespace Illuminate\Support\Traits;

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

trait Macroable
{
    /**
     * The registered string macros.
     *
     * @var array
     */
    protected static $macros = [];

    /**
     * The registered stateful macros.
     *
     * @var array
     */
    protected static $statefulMacros = [];

    /**
     * The reflection cache for mixins.
     *
     * @var array
     */
    protected static $mixinReflectionCache = [];

    /**
     * Register a custom macro.
     *
     * @param  string  $name
     * @param  object|callable  $macro
     * @return void
     */
    public static function macro($name, $macro)
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Register a stateful macro.
     *
     * @param  string  $name
     * @param  callable  $macro
     * @return void
     */
    public static function statefulMacro($name, callable $macro)
    {
        static::$statefulMacros[$name] = $macro;
    }

    /**
     * Register a macro with lifecycle hooks.
     *
     * @param  string  $name
     * @param  callable  $macro
     * @param  callable|null  $before
     * @param  callable|null  $after
     * @return void
     */
    public static function macroWithLifecycle(
        string $name, 
        callable $macro, 
        ?callable $before = null, 
        ?callable $after = null
    ) {
        static::$macros[$name] = function (...$args) use ($macro, $before, $after) {
            if ($before) {
                $before(...$args);
            }
            
            $result = $macro(...$args);
            
            if ($after) {
                $after($result, ...$args);
            }
            
            return $result;
        };
    }

    /**
     * Register a chainable macro.
     *
     * @param  string  $name
     * @param  callable  $macro
     * @return void
     */
    public static function chainableMacro(string $name, callable $macro)
    {
        static::$macros[$name] = function (...$args) use ($macro) {
            $result = $macro(...$args);
            return $result ?? $this;
        };
    }

    /**
     * Register a macro with parameter validation.
     *
     * @param  string  $name
     * @param  array  $signature
     * @param  callable  $macro
     * @return void
     */
    public static function validatedMacro(string $name, array $signature, callable $macro)
    {
        static::$macros[$name] = function (...$args) use ($name, $signature, $macro) {
            if (count($args) < count($signature)) {
                throw new InvalidArgumentException(
                    "Macro {$name} requires at least ".count($signature)." parameters."
                );
            }
            
            return $macro(...$args);
        };
    }

    /**
     * Mix another object into the class.
     *
     * @param  object  $mixin
     * @param  bool  $replace
     * @return void
     *
     * @throws \ReflectionException
     */
    public static function mixin($mixin, $replace = true)
    {
        $mixinClass = get_class($mixin);
        
        if (! isset(static::$mixinReflectionCache[$mixinClass])) {
            static::$mixinReflectionCache[$mixinClass] = (new ReflectionClass($mixin))
                ->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);
        }

        foreach (static::$mixinReflectionCache[$mixinClass] as $method) {
            if ($replace || ! static::hasMacro($method->name)) {
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Checks if macro is registered.
     *
     * @param  string  $name
     * @return bool
     */
    public static function hasMacro($name)
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Checks if stateful macro is registered.
     *
     * @param  string  $name
     * @return bool
     */
    public static function hasStatefulMacro($name)
    {
        return isset(static::$statefulMacros[$name]);
    }

    /**
     * Get all registered macros.
     *
     * @return array
     */
    public static function getMacros(): array
    {
        return static::$macros;
    }

    /**
     * Load macros from array.
     *
     * @param  array  $macros
     * @param  bool  $replace
     * @return void
     */
    public static function loadMacros(array $macros, bool $replace = false)
    {
        foreach ($macros as $name => $macro) {
            if ($replace || ! static::hasMacro($name)) {
                static::macro($name, $macro);
            }
        }
    }

    /**
     * Flush the existing macros.
     *
     * @return void
     */
    public static function flushMacros()
    {
        static::$macros = [];
        static::$statefulMacros = [];
        static::$mixinReflectionCache = [];
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public static function __callStatic($method, $parameters)
    {
        if (static::hasStatefulMacro($method)) {
            $macro = static::$statefulMacros[$method];
            
            if ($macro instanceof Closure) {
                $macro = $macro->bindTo(null, static::class);
            }
            
            return $macro(...$parameters);
        }

        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s does not exist.', static::class, $method
            ));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo(null, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @param  mixed  $default
     * @return mixed
     */
    public function optionalMacro($method, $parameters = [], $default = null)
    {
        if (! static::hasMacro($method)) {
            return value($default);
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($this, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if (static::hasStatefulMacro($method)) {
            $macro = static::$statefulMacros[$method];
            
            if ($macro instanceof Closure) {
                $macro = $macro->bindTo($this, static::class);
            }
            
            return $macro(...$parameters);
        }

        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s does not exist.', static::class, $method
            ));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($this, static::class);
        }

        return $macro(...$parameters);
    }
}
