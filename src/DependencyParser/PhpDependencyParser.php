<?php

namespace ItDependsOn\DependencyParser;

use ItDependsOn\DependencyParser\Adapter\PhpParserAdapter;
use ItDependsOn\DependencyParser\Adapter\NodeFinderAdapter;
use PhpParser\NodeDumper;
use ItDependsOn\DependencyParser\Dto\MethodDependency;
use ItDependsOn\DependencyParser\Dto\Dependency;

class PhpDependencyParser
{
    const TYPE_INJECTED = 'injected';
    const TYPE_INLINE = 'inline';

    /**
     * @param PhpParserAdapter
     */
    protected $phpParser;
    /**
     * @param NodeFinderAdapter 
     */
    protected $nodeFinder;
    protected $nodeDumper;

    public function __construct(PhpParserAdapter $phpParser, NodeFinderAdapter $nodeFinder)
    {
        $this->phpParser = $phpParser;    
        $this->nodeFinder = $nodeFinder;
        $this->nodeDumper = new NodeDumper;
    }

    public function parse(string $code): array
    {
        $ast = $this->phpParser->parse($code);

        return $this->getAllDependency($ast);
    }

    protected function getAllDependency(array $ast): array
    {
        $inline = $this->getInlineDependencies($ast);
        $injected = $this->getInjectedDependencies($ast);

        /**
         * @todo unique merge
         */

        return array_merge($inline, $injected);
    }

    /**
     * Returns all class names that injected to any method, so it appears in the function's parameters with type hint
     */
    protected function getInjectedDependencies(array $ast): array
    {
        $methods = $this->getMethodsWithInjectedDependencies($ast);
        return $this->getUniqueDependenciesByMethods($methods, self::TYPE_INJECTED);
    }

    /**
     * Returns all class names that created in any method
     */
    protected function getInlineDependencies(array $ast): array
    {
        $methods = $this->getMethodsWithInlineDependencies($ast);
        return $this->getUniqueDependenciesByMethods($methods, self::TYPE_INLINE);
    }

    protected function getMethodsWithInjectedDependencies(array $ast): array
    {
        $classMethods = $this->nodeFinder->findInstanceOf($ast, NodeFinderAdapter::CLASS_METHOD_INSTANCE);
        $methodDependencies = [];

        foreach ($classMethods as $classMethod) {
            $methodDependency = new MethodDependency($classMethod->name->name);

            foreach ($classMethod->params as $param) {                            
                $name = $this->nodeFinder->findInstanceOf($param, NodeFinderAdapter::USE_NAME_INSTANCE);

                // if no Node\Name found for this parameter, then it's a simple scalar type paramter
                if (empty($name)) 
                    continue;

                $methodDependency->addParts($name[0]->parts);
            }

            if ($methodDependency->hasDependencies()) 
                $methodDependencies[] = $methodDependency;            
        }

        return $methodDependencies;
    }

    protected function getUniqueDependenciesByMethods(array $methodDependencies, string $type): array
    {
        $dependencies = [];

        foreach ($methodDependencies as $methodDep) {
            /** @var MethodDependency $methodDep */
            foreach ($methodDep->dependencies as $d) {
                if (!array_key_exists($d, $dependencies)) 
                    $dependencies[$d] = new Dependency($d, $type);

                $dependencies[$d]->addMethod($methodDep->method);
            }            
        }

        return $dependencies;
    }

    protected function getMethodsWithInlineDependencies(array $ast): array
    {
        $classMethods = $this->nodeFinder->findInstanceOf($ast, NodeFinderAdapter::CLASS_METHOD_INSTANCE);
        $methodDependencies = [];

        foreach ($classMethods as $classMethod) {
            $methodDependency = new MethodDependency($classMethod->name->name);

            $newExpressions = $this->nodeFinder->findInstanceOf($classMethod->stmts, NodeFinderAdapter::NEW_EXPR);            

            if (empty($newExpressions)) 
                continue;

            foreach ($newExpressions as $newExpr)
                $methodDependency->addParts($newExpr->class->parts);
            
            $methodDependencies[] = $methodDependency;
        }

        return $methodDependencies;
    }

    /**
     * Returns all class names that accours in use statements at the top of the class
     */
    protected function getUsedDependencies(array $ast): array
    {
        $useUseStatements = $this->nodeFinder->findInstanceOf($ast, NodeFinderAdapter::USE_INSTANCE);
        $names = $this->nodeFinder->findInstanceOf($useUseStatements, NodeFinderAdapter::USE_NAME_INSTANCE);

        return array_map(function ($name) {
            return join($name->parts, '\\');
        }, $names);
    }

    protected function dump($ast)
    {
        echo $this->nodeDumper->dump($ast);
    }
}