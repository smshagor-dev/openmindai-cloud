<?php

// Official model contract references for visible but not yet routable services.
// Only concrete working adapters may switch these models to API-ready.
return [
    'models' => [
        'nvidia-3d-body-pose' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Video-frame gRPC inference worker',
            'note' => 'Video body pose estimation uses a streaming gRPC service.',
            'reference' => 'https://build.nvidia.com/nvidia/body-pose/api',
        ],
        'nvidia-nemotron-voicechat' => [
            'transport' => 'realtime_websocket',
            'requirement' => 'Early-access approval and GPU-hosted private realtime relay',
            'note' => 'The backend issues one-time user tickets for NVIDIA official /v1/realtime PCM16 WebSocket. Streaming requires a deployed WSS relay and approved VoiceChat NIM; no public REST inference endpoint is available.',
            'reference' => 'https://developer.nvidia.com/nemotron-voicechat-early-access',
        ],
        'nvidia-background-noise-removal' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Maxine gRPC audio worker',
            'note' => 'Background Noise Removal uses NVIDIA Maxine streaming audio.',
            'reference' => 'https://build.nvidia.com/nvidia/bnr/api',
        ],
        'nvidia-magpie-tts-zeroshot' => [
            'transport' => 'speech_nim',
            'requirement' => 'Approved GPU-hosted Speech NIM and speaker consent for voice prompts',
            'note' => 'Uses the documented /v1/audio/synthesize endpoint with supported built-in voices or consented voice reference. NVIDIA approval and a GPU-backed Speech NIM are still required.',
            'reference' => 'https://docs.nvidia.com/nim/speech/latest/get-started/tutorials/tts.html',
        ],
        'nvidia-studio-voice' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Maxine gRPC audio worker',
            'note' => 'Studio Voice requires 16-bit mono WAV and a gRPC client.',
            'reference' => 'https://build.nvidia.com/nvidia/studiovoice/api',
        ],
        'nvidia-synthetic-video-detector' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Private access approval and gRPC worker',
            'note' => 'Synthetic Video Detector is gated through the AI for Media Private Access Program.',
            'reference' => 'https://build.nvidia.com/nvidia/synthetic-video-detector/api',
        ],
        'nvidia-active-speaker-detection' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Video, audio and diarization gRPC worker',
            'note' => 'Requires synchronized video, audio and speaker diarization inputs.',
            'reference' => 'https://build.nvidia.com/nvidia/active-speaker-detection/api',
        ],
        'nvidia-cosmos-transfer2.5-2b' => [
            'transport' => 'video_nim',
            'requirement' => 'Separate GPU-hosted Cosmos Transfer2.5 NIM',
            'note' => 'Uses a prompt, uploaded MP4 input, and one edge/depth/vis/seg MP4 control through /v1/infer on an operator-managed GPU NIM. Provider access is not live verified.',
            'reference' => 'https://docs.nvidia.com/nim/cosmos/latest/api-reference.html',
        ],
        'nvidia-streampetr' => [
            'transport' => 'av_scene',
            'requirement' => 'Queue-backed hosted scene inference and ZIP results',
            'note' => 'NVIDIA offers predefined nuScenes scene IDs with ZIP or signed-S3 download responses. Live entitlement remains unverified.',
            'reference' => 'https://docs.api.nvidia.com/nim/reference/nvidia-streampetr-infer',
        ],
        'nvidia-sparsedrive' => [
            'transport' => 'self_hosted_av',
            'requirement' => 'Separate GPU-hosted NVIDIA NIM worker',
            'note' => 'This scene inference route is configured against an operator-managed NIM, not a verified public NVIDIA hostname. Provider access is unverified.',
            'reference' => 'https://docs.api.nvidia.com/nim/reference/nvidia-sparsedrive-infer',
        ],
        'nvidia-bevformer' => [
            'transport' => 'self_hosted_av',
            'requirement' => 'Separate GPU-hosted NVIDIA NIM worker',
            'note' => 'Requires operator-managed BEV NIM scene inference with documented inputs and JSON response; NVIDIA public example.com URL is a placeholder.',
            'reference' => 'https://docs.api.nvidia.com/nim/reference/nvidia-bevformer-infer',
        ],
    ],
];
