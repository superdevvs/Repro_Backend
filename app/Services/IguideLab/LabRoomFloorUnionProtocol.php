<?php
namespace App\Services\IguideLab;
abstract class LabRoomFloorUnionProtocol extends LabRoomEvidenceProtocol
{
    public const LAYOUT_VERSION='interior-room-evidence-layout/2';
    public const CANDIDATE_VERSION='interior-room-evidence-placement/2';
    public static function instructions(): string
    {
        return str_replace(['including status, prompt and all four box coordinates','Source floor boxes may use0..2047','Source plan boxes and floor witness remain required.'],
            ['including all source regions, plane evidence and prospective contact envelopes','Source floor rectangles may use0..2047','Source plan boxes and the immutable source floor union remain required.'],parent::instructions())."\n".LabRoomFloorWitnessUnion::instructions();
    }
    public static function snapshot(): array
    {return parent::snapshot()+['unionProtocolSha256'=>hash_file('sha256',__FILE__),'floorUnion'=>LabRoomFloorWitnessUnion::snapshot()];}
    protected static function validateFloor(array $v,string $profileId,?array $objects): void
    {LabRoomFloorWitnessUnion::selection($v,$profileId,$objects);}
    protected static function floorSchema(): array {return LabRoomFloorWitnessUnion::schema();}
}
