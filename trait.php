<?php

class A {
    /** @phpstan-assert array<mixed> $v */
    public static function isArray(mixed $v): void {}
}

trait T {
    /** @param array<mixed> $data */
    public function go(array $data): void {
        A::isArray($data); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }
}
