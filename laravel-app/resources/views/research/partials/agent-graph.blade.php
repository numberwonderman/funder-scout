@php
    $nodeOrder = ['campaign_analyst', 'evidence_researcher', 'people_researcher', 'synthesizer'];
    $nodeMeta = [
        'campaign_analyst' => ['label' => 'Analyst', 'x' => 55, 'y' => 105],
        'evidence_researcher' => ['label' => 'Evidence', 'x' => 225, 'y' => 45],
        'people_researcher' => ['label' => 'People', 'x' => 225, 'y' => 165],
        'synthesizer' => ['label' => 'Synthesis', 'x' => 405, 'y' => 105],
    ];
    $edgeList = [
        ['campaign_analyst', 'evidence_researcher'],
        ['campaign_analyst', 'people_researcher'],
        ['evidence_researcher', 'people_researcher'],
        ['evidence_researcher', 'synthesizer'],
        ['people_researcher', 'synthesizer'],
    ];
    $completedNodes = $completedNodes ?? [];
    $activeNode = $activeNode ?? null;
    $nodeStatus = fn ($node) => in_array($node, $completedNodes, true) ? 'complete' : ($node === $activeNode ? 'active' : 'pending');
    $edgeStatus = function ($from, $to) use ($completedNodes, $nodeStatus) {
        if (in_array($from, $completedNodes, true) && in_array($to, $completedNodes, true)) {
            return 'complete';
        }
        return in_array($from, $completedNodes, true) && $nodeStatus($to) === 'active' ? 'active' : 'pending';
    };
    $summary = collect($nodeOrder)->map(fn ($node) => $nodeMeta[$node]['label'].' '.$nodeStatus($node))->implode(', ');
@endphp
<div class="agent-graph" role="img" aria-label="Strands agent graph progress: {{ $summary }}">
    <svg viewBox="0 0 460 222" preserveAspectRatio="xMidYMid meet">
        @foreach ($edgeList as [$from, $to])
            @php $a = $nodeMeta[$from]; $b = $nodeMeta[$to]; @endphp
            <line class="graph-edge is-{{ $edgeStatus($from, $to) }}" x1="{{ $a['x'] }}" y1="{{ $a['y'] }}" x2="{{ $b['x'] }}" y2="{{ $b['y'] }}" />
        @endforeach
        @foreach ($nodeOrder as $node)
            @php $m = $nodeMeta[$node]; $s = $nodeStatus($node); @endphp
            <g class="graph-node is-{{ $s }}" transform="translate({{ $m['x'] }},{{ $m['y'] }})">
                <circle r="23" />
                @if ($s === 'complete')
                    <text class="node-check" x="0" y="6" text-anchor="middle">✓</text>
                @endif
                <text class="node-label" x="0" y="44" text-anchor="middle">{{ $m['label'] }}</text>
            </g>
        @endforeach
    </svg>
</div>
