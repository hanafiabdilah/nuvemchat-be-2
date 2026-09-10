<?php

use App\Services\FlowAssistant\FlowLayout;

/**
 * A pure function over coordinates — no database, no auth, no HTTP.
 */

/** @param array<string, array{0: int|null, 1: int|null}> $positions key => [x, y] */
function layoutNodes(array $positions): array
{
    $nodes = [];
    $first = true;

    foreach ($positions as $key => [$x, $y]) {
        $nodes[] = [
            'key' => (string) $key,
            'type' => $first ? 'start' : 'message',
            'data' => $first ? null : ['body' => 'oi'],
            'position_x' => $x,
            'position_y' => $y,
        ];
        $first = false;
    }

    return $nodes;
}

/** @param list<array{0: string, 1: string}> $pairs */
function layoutEdges(array $pairs): array
{
    return array_map(fn ($pair) => [
        'source_key' => $pair[0],
        'target_key' => $pair[1],
        'condition_value' => null,
    ], $pairs);
}

function positionOf(array $nodes, string $key): array
{
    foreach ($nodes as $node) {
        if ($node['key'] === $key) {
            return [$node['position_x'], $node['position_y']];
        }
    }

    throw new RuntimeException("no node {$key}");
}

/** Every pair of nodes is far enough apart to be two nodes. */
function expectNoOverlap(array $nodes): void
{
    foreach ($nodes as $i => $a) {
        foreach (array_slice($nodes, $i + 1) as $b) {
            $apartX = abs($a['position_x'] - $b['position_x']) >= FlowLayout::MIN_H_SEPARATION;
            $apartY = abs($a['position_y'] - $b['position_y']) >= FlowLayout::MIN_V_SEPARATION;

            expect($apartX || $apartY)->toBeTrue(
                "nodes {$a['key']} and {$b['key']} overlap"
            );
        }
    }
}

test('inserting a step pushes the rest of the chain along instead of landing on it', function () {
    // The reported bug, exactly: A → B → C, and the assistant adds D between B
    // and C. It wires the edges correctly and hands D the coordinates C already
    // has — it was told to keep the nodes it is not changing, so C comes back
    // where we sent it. Two nodes on one spot reads as a node that never
    // appeared.
    $nodes = layoutNodes([
        'A' => [0, 0],
        'B' => [300, 0],
        'C' => [600, 0],
        'D' => [600, 0],   // ← dropped straight on top of C
    ]);

    $edges = layoutEdges([['A', 'B'], ['B', 'D'], ['D', 'C']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    [$dx] = positionOf($resolved, 'D');
    [$cx] = positionOf($resolved, 'C');

    // D is now the third step and keeps its place; C, which comes after it,
    // is the one that gives way.
    expect($dx)->toBe(600.0)
        ->and($cx)->toBeGreaterThanOrEqual(600.0 + FlowLayout::MIN_H_SEPARATION);

    expectNoOverlap($resolved);
});

test('everything downstream moves, not just the node that was in the way', function () {
    // Shifting C alone would park it on E — the same collision, one step
    // further along. The whole tail travels together.
    $nodes = layoutNodes([
        'A' => [0, 0],
        'B' => [300, 0],
        'C' => [600, 0],
        'E' => [900, 0],
        'D' => [600, 0],
    ]);

    $edges = layoutEdges([['A', 'B'], ['B', 'D'], ['D', 'C'], ['C', 'E']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    [$cx] = positionOf($resolved, 'C');
    [$ex] = positionOf($resolved, 'E');

    expect($ex)->toBeGreaterThan($cx);
    expectNoOverlap($resolved);
});

test('a layout somebody arranged by hand is left exactly as it is', function () {
    // The reason this pass only ever adds space. A flow's positions are usually
    // the work of a person who dragged the nodes where they wanted them, and a
    // pass that recomputed every coordinate would tidy that away every time the
    // assistant was asked for anything at all.
    $nodes = layoutNodes([
        'A' => [0, 0],
        'B' => [1000, 480],
        'C' => [220, 900],
    ]);

    $edges = layoutEdges([['A', 'B'], ['B', 'C']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    expect(positionOf($resolved, 'A'))->toBe([0.0, 0.0])
        ->and(positionOf($resolved, 'B'))->toBe([1000.0, 480.0])
        ->and(positionOf($resolved, 'C'))->toBe([220.0, 900.0]);
});

test('two branches sitting side by side are not pushed apart horizontally', function () {
    // They are peers, they are already clear of each other vertically, and
    // pushing one right would misrepresent the flow as having an extra step.
    $nodes = layoutNodes([
        'A' => [0, 0],
        'yes' => [300, -220],
        'no' => [300, 220],
    ]);

    $edges = layoutEdges([['A', 'yes'], ['A', 'no']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    expect(positionOf($resolved, 'yes'))->toBe([300.0, -220.0])
        ->and(positionOf($resolved, 'no'))->toBe([300.0, 220.0]);
});

test('a node with no position at all is placed beside its parent', function () {
    $nodes = layoutNodes([
        'A' => [0, 0],
        'B' => [300, 140],
        'C' => [null, null],
    ]);

    $edges = layoutEdges([['A', 'B'], ['B', 'C']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    [$cx, $cy] = positionOf($resolved, 'C');

    expect($cx)->toBe(300.0 + FlowLayout::COLUMN)
        ->and($cy)->toBe(140.0);
});

test('a node reached by two routes sits after the longer one', function () {
    // A diamond: A goes straight to C and also to C by way of B. The shortest
    // route says C belongs beside B, which puts it in B's column; the longest
    // one puts it after B, which is where it belongs.
    $nodes = layoutNodes([
        'A' => [0, 0],
        'B' => [300, 0],
        'C' => [300, 0],
    ]);

    $edges = layoutEdges([['A', 'B'], ['B', 'C'], ['A', 'C']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    [$bx] = positionOf($resolved, 'B');
    [$cx] = positionOf($resolved, 'C');

    expect($cx)->toBeGreaterThan($bx);
    expectNoOverlap($resolved);
});

test('a flow that loops back on itself settles instead of spinning', function () {
    // "Further from the start" is ill-defined once there is a cycle, so the
    // sweep cannot be proven to settle — it is capped rather than allowed to
    // spin. What matters here is that it returns.
    $nodes = layoutNodes([
        'A' => [0, 0],
        'B' => [300, 0],
        'C' => [300, 0],
    ]);

    $edges = layoutEdges([['A', 'B'], ['B', 'C'], ['C', 'B']]);

    $resolved = FlowLayout::resolve($nodes, $edges);

    expect($resolved)->toHaveCount(3);
});

test('a single node needs no room made for it', function () {
    $nodes = layoutNodes(['A' => [0, 0]]);

    expect(FlowLayout::resolve($nodes, []))->toBe($nodes);
});
