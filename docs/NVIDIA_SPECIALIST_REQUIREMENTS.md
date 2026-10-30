# NVIDIA specialist inference requirements

All 42 model entries remain in the OpenMindAI Cloud catalog. Registered model visibility is **not** evidence of a working remote inference route. Eleven specialist entries still require dedicated NVIDIA protocols or provider approval after the completion of Cosmos3 Nano queued generation in PR #16.

The backend now surfaces exact per-model protocol and access requirements in **Admin → NVIDIA Models** and authenticated catalog metadata. An admin cannot force these models into JSON/chat-ready status without implementing the missing transport. No arbitrary URL, signing secret or user-provided NVIDIA key is accepted.

The published NVIDIA model/API pages for the affected models are linked directly from each admin card. Categories:
- **Maxine gRPC worker**: 3D Body Pose, Background Noise Removal, Studio Voice, Active Speaker Detection, Synthetic Video Detector (private access approval required).
- **Access restricted speech**: Nemotron 3 VoiceChat (early access, full-duplex realtime) and Magpie ZeroShot (apply for access and speaker consent).
- **Video/control workflow**: Cosmos Transfer2.5 (structured control-video input).
- **Automotive perception/decision**: StreamPETR, SparseDrive and BEVFormer (temporal, structured scene inputs).

Production activation requires the NVIDIA account entitlement, official request transport, a worker capable of processing required audio/video inputs on your hosting setup, secure storage, quotas, provider error handling and reproducible real live tests. Plain PHP/HTTP cPanel relay cannot automatically substitute for a gRPC or realtime streaming service.

The NVIDIA free-endpoint label describes the catalog trial service, not unlimited access, and is independent from the user's OpenMindAI Developer API Key.
