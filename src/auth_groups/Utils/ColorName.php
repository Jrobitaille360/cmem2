<?php

namespace AuthGroups\Utils;

/**
 * ColorName - Énumération de couleurs HTML avec codes hexadécimaux et valeurs RGB
 * Converti depuis Java ColorName enum
 */
class ColorName
{
    // Propriétés
    private string $name;
    private string $hex;
    private int $red;
    private int $green;
    private int $blue;

    // Constantes de couleurs - Red HTML Color Names
    public const INDIANRED = ['hex' => '#CD5C5C', 'r' => 205, 'g' => 92, 'b' => 92];
    public const LIGHTCORAL = ['hex' => '#F08080', 'r' => 240, 'g' => 128, 'b' => 128];
    public const SALMON = ['hex' => '#FA8072', 'r' => 250, 'g' => 128, 'b' => 114];
    public const DARKSALMON = ['hex' => '#E9967A', 'r' => 233, 'g' => 150, 'b' => 122];
    public const CRIMSON = ['hex' => '#DC143C', 'r' => 220, 'g' => 20, 'b' => 60];
    public const RED = ['hex' => '#FF0000', 'r' => 255, 'g' => 0, 'b' => 0];
    public const FIREBRICK = ['hex' => '#B22222', 'r' => 178, 'g' => 34, 'b' => 34];
    public const DARKRED = ['hex' => '#8B0000', 'r' => 139, 'g' => 0, 'b' => 0];

    // Pink HTML Color Names
    public const PINK = ['hex' => '#FFC0CB', 'r' => 255, 'g' => 192, 'b' => 203];
    public const LIGHTPINK = ['hex' => '#FFB6C1', 'r' => 255, 'g' => 182, 'b' => 193];
    public const HOTPINK = ['hex' => '#FF69B4', 'r' => 255, 'g' => 105, 'b' => 180];
    public const DEEPPINK = ['hex' => '#FF1493', 'r' => 255, 'g' => 20, 'b' => 147];
    public const MEDIUMVIOLETRED = ['hex' => '#C71585', 'r' => 199, 'g' => 21, 'b' => 133];
    public const PALEVIOLETRED = ['hex' => '#DB7093', 'r' => 219, 'g' => 112, 'b' => 147];

    // Orange HTML Color Names
    public const LIGHTSALMON = ['hex' => '#FFA07A', 'r' => 255, 'g' => 160, 'b' => 122];
    public const CORAL = ['hex' => '#FF7F50', 'r' => 255, 'g' => 127, 'b' => 80];
    public const TOMATO = ['hex' => '#FF6347', 'r' => 255, 'g' => 99, 'b' => 71];
    public const ORANGERED = ['hex' => '#FF4500', 'r' => 255, 'g' => 69, 'b' => 0];
    public const DARKORANGE = ['hex' => '#FF8C00', 'r' => 255, 'g' => 140, 'b' => 0];
    public const ORANGE = ['hex' => '#FFA500', 'r' => 255, 'g' => 165, 'b' => 0];

    // Yellow HTML Color Names
    public const GOLD = ['hex' => '#FFD700', 'r' => 255, 'g' => 215, 'b' => 0];
    public const YELLOW = ['hex' => '#FFFF00', 'r' => 255, 'g' => 255, 'b' => 0];
    public const LIGHTYELLOW = ['hex' => '#FFFFE0', 'r' => 255, 'g' => 255, 'b' => 224];
    public const LEMONCHIFFON = ['hex' => '#FFFACD', 'r' => 255, 'g' => 250, 'b' => 205];
    public const LIGHTGOLDENRODYELLOW = ['hex' => '#FAFAD2', 'r' => 250, 'g' => 250, 'b' => 210];
    public const PAPAYAWHIP = ['hex' => '#FFEFD5', 'r' => 255, 'g' => 239, 'b' => 213];
    public const MOCCASIN = ['hex' => '#FFE4B5', 'r' => 255, 'g' => 228, 'b' => 181];
    public const PEACHPUFF = ['hex' => '#FFDAB9', 'r' => 255, 'g' => 218, 'b' => 185];
    public const PALEGOLDENROD = ['hex' => '#EEE8AA', 'r' => 238, 'g' => 232, 'b' => 170];
    public const KHAKI = ['hex' => '#F0E68C', 'r' => 240, 'g' => 230, 'b' => 140];
    public const DARKKHAKI = ['hex' => '#BDB76B', 'r' => 189, 'g' => 183, 'b' => 107];

    // Purple HTML Color Names
    public const LAVENDER = ['hex' => '#E6E6FA', 'r' => 230, 'g' => 230, 'b' => 250];
    public const THISTLE = ['hex' => '#D8BFD8', 'r' => 216, 'g' => 191, 'b' => 216];
    public const PLUM = ['hex' => '#DDA0DD', 'r' => 221, 'g' => 160, 'b' => 221];
    public const VIOLET = ['hex' => '#EE82EE', 'r' => 238, 'g' => 130, 'b' => 238];
    public const ORCHID = ['hex' => '#DA70D6', 'r' => 218, 'g' => 112, 'b' => 214];
    public const FUCHSIA = ['hex' => '#FF00FF', 'r' => 255, 'g' => 0, 'b' => 255];
    public const MAGENTA = ['hex' => '#FF00FF', 'r' => 255, 'g' => 0, 'b' => 255];
    public const MEDIUMORCHID = ['hex' => '#BA55D3', 'r' => 186, 'g' => 85, 'b' => 211];
    public const MEDIUMPURPLE = ['hex' => '#9370DB', 'r' => 147, 'g' => 112, 'b' => 219];
    public const REBECCAPURPLE = ['hex' => '#663399', 'r' => 102, 'g' => 51, 'b' => 153];
    public const BLUEVIOLET = ['hex' => '#8A2BE2', 'r' => 138, 'g' => 43, 'b' => 226];
    public const DARKVIOLET = ['hex' => '#9400D3', 'r' => 148, 'g' => 0, 'b' => 211];
    public const DARKORCHID = ['hex' => '#9932CC', 'r' => 153, 'g' => 50, 'b' => 204];
    public const DARKMAGENTA = ['hex' => '#8B008B', 'r' => 139, 'g' => 0, 'b' => 139];
    public const PURPLE = ['hex' => '#800080', 'r' => 128, 'g' => 0, 'b' => 128];
    public const INDIGO = ['hex' => '#4B0082', 'r' => 75, 'g' => 0, 'b' => 130];
    public const SLATEBLUE = ['hex' => '#6A5ACD', 'r' => 106, 'g' => 90, 'b' => 205];
    public const DARKSLATEBLUE = ['hex' => '#483D8B', 'r' => 72, 'g' => 61, 'b' => 139];
    public const MEDIUMSLATEBLUE = ['hex' => '#7B68EE', 'r' => 123, 'g' => 104, 'b' => 238];

    // Green HTML Color Names
    public const GREENYELLOW = ['hex' => '#ADFF2F', 'r' => 173, 'g' => 255, 'b' => 47];
    public const CHARTREUSE = ['hex' => '#7FFF00', 'r' => 127, 'g' => 255, 'b' => 0];
    public const LAWNGREEN = ['hex' => '#7CFC00', 'r' => 124, 'g' => 252, 'b' => 0];
    public const LIME = ['hex' => '#00FF00', 'r' => 0, 'g' => 255, 'b' => 0];
    public const LIMEGREEN = ['hex' => '#32CD32', 'r' => 50, 'g' => 205, 'b' => 50];
    public const PALEGREEN = ['hex' => '#98FB98', 'r' => 152, 'g' => 251, 'b' => 152];
    public const LIGHTGREEN = ['hex' => '#90EE90', 'r' => 144, 'g' => 238, 'b' => 144];
    public const MEDIUMSPRINGGREEN = ['hex' => '#00FA9A', 'r' => 0, 'g' => 250, 'b' => 154];
    public const SPRINGGREEN = ['hex' => '#00FF7F', 'r' => 0, 'g' => 255, 'b' => 127];
    public const MEDIUMSEAGREEN = ['hex' => '#3CB371', 'r' => 60, 'g' => 179, 'b' => 113];
    public const SEAGREEN = ['hex' => '#2E8B57', 'r' => 46, 'g' => 139, 'b' => 87];
    public const FORESTGREEN = ['hex' => '#228B22', 'r' => 34, 'g' => 139, 'b' => 34];
    public const GREEN = ['hex' => '#008000', 'r' => 0, 'g' => 128, 'b' => 0];
    public const DARKGREEN = ['hex' => '#006400', 'r' => 0, 'g' => 100, 'b' => 0];
    public const YELLOWGREEN = ['hex' => '#9ACD32', 'r' => 154, 'g' => 205, 'b' => 50];
    public const OLIVEDRAB = ['hex' => '#6B8E23', 'r' => 107, 'g' => 142, 'b' => 35];
    public const OLIVE = ['hex' => '#808000', 'r' => 128, 'g' => 128, 'b' => 0];
    public const DARKOLIVEGREEN = ['hex' => '#556B2F', 'r' => 85, 'g' => 107, 'b' => 47];
    public const MEDIUMAQUAMARINE = ['hex' => '#66CDAA', 'r' => 102, 'g' => 205, 'b' => 170];
    public const DARKSEAGREEN = ['hex' => '#8FBC8B', 'r' => 143, 'g' => 188, 'b' => 139];
    public const LIGHTSEAGREEN = ['hex' => '#20B2AA', 'r' => 32, 'g' => 178, 'b' => 170];
    public const DARKCYAN = ['hex' => '#008B8B', 'r' => 0, 'g' => 139, 'b' => 139];
    public const TEAL = ['hex' => '#008080', 'r' => 0, 'g' => 128, 'b' => 128];

    // Blue HTML Color Names
    public const AQUA = ['hex' => '#00FFFF', 'r' => 0, 'g' => 255, 'b' => 255];
    public const CYAN = ['hex' => '#00FFFF', 'r' => 0, 'g' => 255, 'b' => 255];
    public const LIGHTCYAN = ['hex' => '#E0FFFF', 'r' => 224, 'g' => 255, 'b' => 255];
    public const PALETURQUOISE = ['hex' => '#AFEEEE', 'r' => 175, 'g' => 238, 'b' => 238];
    public const AQUAMARINE = ['hex' => '#7FFFD4', 'r' => 127, 'g' => 255, 'b' => 212];
    public const TURQUOISE = ['hex' => '#40E0D0', 'r' => 64, 'g' => 224, 'b' => 208];
    public const MEDIUMTURQUOISE = ['hex' => '#48D1CC', 'r' => 72, 'g' => 209, 'b' => 204];
    public const DARKTURQUOISE = ['hex' => '#00CED1', 'r' => 0, 'g' => 206, 'b' => 209];
    public const CADETBLUE = ['hex' => '#5F9EA0', 'r' => 95, 'g' => 158, 'b' => 160];
    public const STEELBLUE = ['hex' => '#4682B4', 'r' => 70, 'g' => 130, 'b' => 180];
    public const LIGHTSTEELBLUE = ['hex' => '#B0C4DE', 'r' => 176, 'g' => 196, 'b' => 222];
    public const POWDERBLUE = ['hex' => '#B0E0E6', 'r' => 176, 'g' => 224, 'b' => 230];
    public const LIGHTBLUE = ['hex' => '#ADD8E6', 'r' => 173, 'g' => 216, 'b' => 230];
    public const SKYBLUE = ['hex' => '#87CEEB', 'r' => 135, 'g' => 206, 'b' => 235];
    public const LIGHTSKYBLUE = ['hex' => '#87CEFA', 'r' => 135, 'g' => 206, 'b' => 250];
    public const DEEPSKYBLUE = ['hex' => '#00BFFF', 'r' => 0, 'g' => 191, 'b' => 255];
    public const DODGERBLUE = ['hex' => '#1E90FF', 'r' => 30, 'g' => 144, 'b' => 255];
    public const CORNFLOWERBLUE = ['hex' => '#6495ED', 'r' => 100, 'g' => 149, 'b' => 237];
    public const ROYALBLUE = ['hex' => '#4169E1', 'r' => 65, 'g' => 105, 'b' => 225];
    public const BLUE = ['hex' => '#0000FF', 'r' => 0, 'g' => 0, 'b' => 255];
    public const MEDIUMBLUE = ['hex' => '#0000CD', 'r' => 0, 'g' => 0, 'b' => 205];
    public const DARKBLUE = ['hex' => '#00008B', 'r' => 0, 'g' => 0, 'b' => 139];
    public const NAVY = ['hex' => '#000080', 'r' => 0, 'g' => 0, 'b' => 128];
    public const MIDNIGHTBLUE = ['hex' => '#191970', 'r' => 25, 'g' => 25, 'b' => 112];

    // Brown HTML Color Names
    public const CORNSILK = ['hex' => '#FFF8DC', 'r' => 255, 'g' => 248, 'b' => 220];
    public const BLANCHEDALMOND = ['hex' => '#FFEBCD', 'r' => 255, 'g' => 235, 'b' => 205];
    public const BISQUE = ['hex' => '#FFE4C4', 'r' => 255, 'g' => 228, 'b' => 196];
    public const NAVAJOWHITE = ['hex' => '#FFDEAD', 'r' => 255, 'g' => 222, 'b' => 173];
    public const WHEAT = ['hex' => '#F5DEB3', 'r' => 245, 'g' => 222, 'b' => 179];
    public const BURLYWOOD = ['hex' => '#DEB887', 'r' => 222, 'g' => 184, 'b' => 135];
    public const TAN = ['hex' => '#D2B48C', 'r' => 210, 'g' => 180, 'b' => 140];
    public const ROSYBROWN = ['hex' => '#BC8F8F', 'r' => 188, 'g' => 143, 'b' => 143];
    public const SANDYBROWN = ['hex' => '#F4A460', 'r' => 244, 'g' => 164, 'b' => 96];
    public const GOLDENROD = ['hex' => '#DAA520', 'r' => 218, 'g' => 165, 'b' => 32];
    public const DARKGOLDENROD = ['hex' => '#B8860B', 'r' => 184, 'g' => 134, 'b' => 11];
    public const PERU = ['hex' => '#CD853F', 'r' => 205, 'g' => 133, 'b' => 63];
    public const CHOCOLATE = ['hex' => '#D2691E', 'r' => 210, 'g' => 105, 'b' => 30];
    public const SADDLEBROWN = ['hex' => '#8B4513', 'r' => 139, 'g' => 69, 'b' => 19];
    public const SIENNA = ['hex' => '#A0522D', 'r' => 160, 'g' => 82, 'b' => 45];
    public const BROWN = ['hex' => '#A52A2A', 'r' => 165, 'g' => 42, 'b' => 42];
    public const MAROON = ['hex' => '#800000', 'r' => 128, 'g' => 0, 'b' => 0];

    // White HTML Color Names
    public const WHITE = ['hex' => '#FFFFFF', 'r' => 255, 'g' => 255, 'b' => 255];
    public const SNOW = ['hex' => '#FFFAFA', 'r' => 255, 'g' => 250, 'b' => 250];
    public const HONEYDEW = ['hex' => '#F0FFF0', 'r' => 240, 'g' => 255, 'b' => 240];
    public const MINTCREAM = ['hex' => '#F5FFFA', 'r' => 245, 'g' => 255, 'b' => 250];
    public const AZURE = ['hex' => '#F0FFFF', 'r' => 240, 'g' => 255, 'b' => 255];
    public const ALICEBLUE = ['hex' => '#F0F8FF', 'r' => 240, 'g' => 248, 'b' => 255];
    public const GHOSTWHITE = ['hex' => '#F8F8FF', 'r' => 248, 'g' => 248, 'b' => 255];
    public const WHITESMOKE = ['hex' => '#F5F5F5', 'r' => 245, 'g' => 245, 'b' => 245];
    public const SEASHELL = ['hex' => '#FFF5EE', 'r' => 255, 'g' => 245, 'b' => 238];
    public const BEIGE = ['hex' => '#F5F5DC', 'r' => 245, 'g' => 245, 'b' => 220];
    public const OLDLACE = ['hex' => '#FDF5E6', 'r' => 253, 'g' => 245, 'b' => 230];
    public const FLORALWHITE = ['hex' => '#FFFAF0', 'r' => 255, 'g' => 250, 'b' => 240];
    public const IVORY = ['hex' => '#FFFFF0', 'r' => 255, 'g' => 255, 'b' => 240];
    public const ANTIQUEWHITE = ['hex' => '#FAEBD7', 'r' => 250, 'g' => 235, 'b' => 215];
    public const LINEN = ['hex' => '#FAF0E6', 'r' => 250, 'g' => 240, 'b' => 230];
    public const LAVENDERBLUSH = ['hex' => '#FFF0F5', 'r' => 255, 'g' => 240, 'b' => 245];
    public const MISTYROSE = ['hex' => '#FFE4E1', 'r' => 255, 'g' => 228, 'b' => 225];

    // Gray HTML Color Names
    public const GAINSBORO = ['hex' => '#DCDCDC', 'r' => 220, 'g' => 220, 'b' => 220];
    public const LIGHTGRAY = ['hex' => '#D3D3D3', 'r' => 211, 'g' => 211, 'b' => 211];
    public const SILVER = ['hex' => '#C0C0C0', 'r' => 192, 'g' => 192, 'b' => 192];
    public const DARKGRAY = ['hex' => '#A9A9A9', 'r' => 169, 'g' => 169, 'b' => 169];
    public const GRAY = ['hex' => '#808080', 'r' => 128, 'g' => 128, 'b' => 128];
    public const DIMGRAY = ['hex' => '#696969', 'r' => 105, 'g' => 105, 'b' => 105];
    public const LIGHTSLATEGRAY = ['hex' => '#778899', 'r' => 119, 'g' => 136, 'b' => 153];
    public const SLATEGRAY = ['hex' => '#708090', 'r' => 112, 'g' => 128, 'b' => 144];
    public const DARKSLATEGRAY = ['hex' => '#2F4F4F', 'r' => 47, 'g' => 79, 'b' => 79];
    public const BLACK = ['hex' => '#000000', 'r' => 0, 'g' => 0, 'b' => 0];

    /**
     * Constructeur
     */
    public function __construct(string $name, string $hex, int $red, int $green, int $blue)
    {
        $this->name = $name;
        $this->hex = $hex;
        $this->red = $red;
        $this->green = $green;
        $this->blue = $blue;
    }

    /**
     * Crée une instance ColorName à partir d'une constante
     */
    public static function fromConstant(string $constantName): self
    {
        $constant = constant('self::' . strtoupper($constantName));
        return new self(
            strtoupper($constantName),
            $constant['hex'],
            $constant['r'],
            $constant['g'],
            $constant['b']
        );
    }

    /**
     * Retourne le code hexadécimal
     */
    public function hex(): string
    {
        return $this->hex;
    }

    /**
     * Retourne la composante rouge
     */
    public function red(): int
    {
        return $this->red;
    }

    /**
     * Retourne la composante verte
     */
    public function green(): int
    {
        return $this->green;
    }

    /**
     * Retourne la composante bleue
     */
    public function blue(): int
    {
        return $this->blue;
    }

    /**
     * Retourne la valeur entière de la couleur
     */
    public function integer(): int
    {
        return (($this->red * 256 + $this->green) * 256 + $this->blue);
    }

    /**
     * Retourne un tableau RGB
     */
    public function rgb(): array
    {
        return [
            'r' => $this->red,
            'g' => $this->green,
            'b' => $this->blue
        ];
    }

    /**
     * Retourne une chaîne de couleur CSS rgb()
     */
    public function rgbString(): string
    {
        return "rgb({$this->red}, {$this->green}, {$this->blue})";
    }

    /**
     * Retourne le nom de la couleur
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Représentation en chaîne
     */
    public function __toString(): string
    {
        return "Color[r={$this->red}, g={$this->green}, b={$this->blue}]";
    }

    /**
     * Retourne toutes les couleurs disponibles
     */
    public static function getAllColors(): array
    {
        $reflection = new \ReflectionClass(self::class);
        return $reflection->getConstants();
    }

    /**
     * Trouve le nom de couleur le plus proche d'un code hexadécimal
     */
    public static function findClosest(string $hex): ?string
    {
        $hex = strtoupper(str_replace('#', '', $hex));
        
        if (strlen($hex) !== 6) {
            return null;
        }

        $targetR = hexdec(substr($hex, 0, 2));
        $targetG = hexdec(substr($hex, 2, 2));
        $targetB = hexdec(substr($hex, 4, 2));

        $minDistance = PHP_INT_MAX;
        $closestColor = null;

        foreach (self::getAllColors() as $name => $color) {
            $distance = sqrt(
                pow($color['r'] - $targetR, 2) +
                pow($color['g'] - $targetG, 2) +
                pow($color['b'] - $targetB, 2)
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closestColor = $name;
            }
        }

        return $closestColor;
    }

    /**
     * Vérifie si un code hexadécimal est valide
     */
    public static function isValidHex(string $hex): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1;
    }

    /**
     * Convertit RGB en hexadécimal
     */
    public static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * Convertit hexadécimal en RGB
     */
    public static function hexToRgb(string $hex): ?array
    {
        $hex = str_replace('#', '', $hex);
        
        if (strlen($hex) !== 6) {
            return null;
        }

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }

    // ==================== UTILITAIRES COLORUTIL ====================

    public const DEFAULT_COLOR_STRING = "RED";

    /**
     * Parse une couleur à partir d'un objet (entier ou string)
     * @param mixed $o - Integer, Float, String
     * @return array|null RGB array ['r' => int, 'g' => int, 'b' => int]
     */
    public static function parseColor($o): ?array
    {
        if (is_int($o) || is_float($o)) {
            $intVal = (int)$o;
            return [
                'r' => ($intVal >> 16) & 0xFF,
                'g' => ($intVal >> 8) & 0xFF,
                'b' => $intVal & 0xFF
            ];
        }
        if (is_string($o)) {
            return self::stringToColor($o);
        }
        return null;
    }

    /**
     * Convertit une string en couleur RGB
     * Formats supportés:
     * - Nom de couleur: "RED", "BLUE", etc.
     * - Hexadécimal: "0xFFFFFF", "#FFFFFF", "%23FFFFFF"
     * - HSL: "HSL(360, 50%, 50%)"
     * - HSLA: "HSLA(360, 50%, 50%, 1)"
     * - RGB: "RGB(255, 255, 255)"
     * - RGBA: "RGBA(255, 255, 255, 1)"
     * 
     * @param string|null $colorString
     * @return array|null RGB array avec optionnellement 'a' pour alpha
     */
    public static function stringToColor(?string $colorString): ?array
    {
        if ($colorString === null) {
            $colorString = self::DEFAULT_COLOR_STRING;
        }
        
        $colorString = strtoupper(trim($colorString));
        $colorString = self::unaccent($colorString);

        // Essayer de trouver une couleur nommée
        $constants = self::getAllColors();
        if (isset($constants[$colorString])) {
            return [
                'r' => $constants[$colorString]['r'],
                'g' => $constants[$colorString]['g'],
                'b' => $constants[$colorString]['b']
            ];
        }

        // Format hexadécimal
        if (substr($colorString, 0, 2) === '0X') {
            return self::colorHexFromString($colorString);
        }
        if (substr($colorString, 0, 1) === '#') {
            return self::colorHexFromString('0x' . substr($colorString, 1));
        }
        if (substr($colorString, 0, 3) === '%23') {
            return self::colorHexFromString('0x' . substr($colorString, 3));
        }

        // Formats fonctionnels
        if (substr($colorString, 0, 4) === 'HSLA') {
            return self::colorHSLA($colorString);
        }
        if (substr($colorString, 0, 3) === 'HSL') {
            return self::colorHSL($colorString);
        }
        if (substr($colorString, 0, 4) === 'RGBA') {
            return self::colorRGBA($colorString);
        }
        if (substr($colorString, 0, 3) === 'RGB') {
            return self::colorRGB($colorString);
        }

        return null;
    }

    /**
     * Parse une fonction de couleur (RGB, HSL, etc.)
     * @param string $funcString - ex: "RGB(255, 128, 0)"
     * @return array - [nom_fonction, param1, param2, ...]
     */
    private static function parseFunction(string $funcString): array
    {
        $funcString = trim($funcString);
        $openParen = strpos($funcString, '(');
        $closeParen = strrpos($funcString, ')');
        
        if ($openParen === false || $closeParen === false) {
            return [];
        }

        $funcName = strtoupper(substr($funcString, 0, $openParen));
        $params = substr($funcString, $openParen + 1, $closeParen - $openParen - 1);
        $paramArray = array_map('trim', explode(',', $params));
        
        array_unshift($paramArray, $funcName);
        return $paramArray;
    }

    /**
     * Parse un pourcentage en float (0..1)
     */
    private static function parsePercentage(string $str): float
    {
        $str = trim($str);
        if (substr($str, -1) === '%') {
            return (float)substr($str, 0, -1) / 100.0;
        }
        return (float)$str;
    }

    /**
     * Format RGB(r, g, b)
     */
    private static function colorRGB(string $colorString): ?array
    {
        $params = self::parseFunction($colorString);
        if (!isset($params[0]) || $params[0] !== 'RGB' || count($params) < 4) {
            return null;
        }
        
        return [
            'r' => (int)$params[1],
            'g' => (int)$params[2],
            'b' => (int)$params[3]
        ];
    }

    /**
     * Format RGBA(r, g, b, a)
     */
    private static function colorRGBA(string $colorString): ?array
    {
        $params = self::parseFunction($colorString);
        if (!isset($params[0]) || $params[0] !== 'RGBA' || count($params) < 5) {
            return null;
        }
        
        return [
            'r' => (int)$params[1],
            'g' => (int)$params[2],
            'b' => (int)$params[3],
            'a' => (float)$params[4]
        ];
    }

    /**
     * Format HSL(h, s%, l%)
     */
    private static function colorHSL(string $colorString): ?array
    {
        $params = self::parseFunction($colorString);
        if (!isset($params[0]) || $params[0] !== 'HSL' || count($params) < 4) {
            return null;
        }
        
        $h = (float)$params[1];
        $s = self::parsePercentage($params[2]) * 100; // Convertir en 0-100
        $l = self::parsePercentage($params[3]) * 100; // Convertir en 0-100
        
        return self::hslToRGBArray($h, $s, $l);
    }

    /**
     * Format HSLA(h, s%, l%, a)
     */
    private static function colorHSLA(string $colorString): ?array
    {
        $params = self::parseFunction($colorString);
        if (!isset($params[0]) || $params[0] !== 'HSLA' || count($params) < 5) {
            return null;
        }
        
        $h = (float)$params[1];
        $s = self::parsePercentage($params[2]) * 100;
        $l = self::parsePercentage($params[3]) * 100;
        $a = (float)$params[4];
        
        $rgb = self::hslToRGBArray($h, $s, $l, $a);
        return $rgb;
    }

    /**
     * Parse hexadécimal (0xFFFFFF)
     */
    private static function colorHexFromString(string $colorString): ?array
    {
        $hex = substr($colorString, 2); // Remove "0x"
        $intVal = hexdec($hex);
        
        return [
            'r' => ($intVal >> 16) & 0xFF,
            'g' => ($intVal >> 8) & 0xFF,
            'b' => $intVal & 0xFF
        ];
    }

    /**
     * Convertit RGB en HSL
     * @param int $r - 0-255
     * @param int $g - 0-255
     * @param int $b - 0-255
     * @return array ['h' => 0-360, 's' => 0-100, 'l' => 0-100]
     */
    public static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r = $r / 255.0;
        $g = $g / 255.0;
        $b = $b / 255.0;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2.0;

        if ($max == $min) {
            $h = $s = 0.0;
        } else {
            $d = $max - $min;
            $s = ($l > 0.5) ? $d / (2.0 - $max - $min) : $d / ($max + $min);

            if ($r > $g && $r > $b) {
                $h = ($g - $b) / $d + ($g < $b ? 6.0 : 0.0);
            } elseif ($g > $b) {
                $h = ($b - $r) / $d + 2.0;
            } else {
                $h = ($r - $g) / $d + 4.0;
            }
            $h /= 6.0;
        }

        return [
            'h' => $h * 360,
            's' => $s * 100,
            'l' => $l * 100
        ];
    }

    /**
     * Convertit HSL en RGB
     * @param float $h - Hue (0-360)
     * @param float $s - Saturation (0-100)
     * @param float $l - Luminance (0-100)
     * @param float $alpha - Alpha (0-1), optionnel
     * @return array RGB avec optionnellement alpha
     */
    public static function hslToRGBArray(float $h, float $s, float $l, float $alpha = 1.0): array
    {
        if ($s < 0.0 || $s > 100.0) {
            throw new \InvalidArgumentException("Saturation hors limites: $s");
        }
        if ($l < 0.0 || $l > 100.0) {
            throw new \InvalidArgumentException("Luminance hors limites: $l");
        }
        if ($alpha < 0.0 || $alpha > 1.0) {
            throw new \InvalidArgumentException("Alpha hors limites: $alpha");
        }

        $h = fmod($h, 360.0);
        $h /= 360.0;
        $s /= 100.0;
        $l /= 100.0;

        if ($s == 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = ($l < 0.5) ? $l * (1 + $s) : $l + $s - ($s * $l);
            $p = 2 * $l - $q;

            $r = max(0, self::hueToRgbValue($p, $q, $h + (1.0 / 3.0)));
            $g = max(0, self::hueToRgbValue($p, $q, $h));
            $b = max(0, self::hueToRgbValue($p, $q, $h - (1.0 / 3.0)));
        }

        $r = min($r, 1.0);
        $g = min($g, 1.0);
        $b = min($b, 1.0);

        $result = [
            'r' => (int)round($r * 255),
            'g' => (int)round($g * 255),
            'b' => (int)round($b * 255)
        ];

        if ($alpha < 1.0) {
            $result['a'] = $alpha;
        }

        return $result;
    }

    /**
     * Helper pour convertir hue en RGB
     */
    private static function hueToRgbValue(float $p, float $q, float $h): float
    {
        if ($h < 0) $h += 1;
        if ($h > 1) $h -= 1;
        
        if (6 * $h < 1) {
            return $p + (($q - $p) * 6 * $h);
        }
        if (2 * $h < 1) {
            return $q;
        }
        if (3 * $h < 2) {
            return $p + (($q - $p) * 6 * ((2.0 / 3.0) - $h));
        }
        return $p;
    }

    /**
     * Interpolation linéaire HSL entre deux couleurs
     * @param array $c1 - Couleur RGB de départ
     * @param array $c2 - Couleur RGB de fin
     * @param float $partage - Point de partage (0..1)
     * @return array - Couleur RGB interpolée
     */
    public static function interpolateLinearHSLColor(array $c1, array $c2, float $partage): array
    {
        $c1HSL = self::rgbToHsl($c1['r'], $c1['g'], $c1['b']);
        $c2HSL = self::rgbToHsl($c2['r'], $c2['g'], $c2['b']);

        $h = $c1HSL['h'] + $partage * ($c2HSL['h'] - $c1HSL['h']);
        $s = $c1HSL['s'] + $partage * ($c2HSL['s'] - $c1HSL['s']);
        $l = $c1HSL['l'] + $partage * ($c2HSL['l'] - $c1HSL['l']);

        return self::hslToRGBArray($h, $s, $l);
    }

    /**
     * Retourne la chaîne hexadécimale de la couleur
     * @param array $color - ['r' => int, 'g' => int, 'b' => int]
     * @param string $prefix - Préfixe (par défaut "#")
     * @return string
     */
    public static function colorToHexString(array $color, string $prefix = '#'): string
    {
        return sprintf('%s%02x%02x%02x', $prefix, $color['r'], $color['g'], $color['b']);
    }

    /**
     * Retourne la chaîne hexadécimale SVG
     */
    public static function svgHexString(array $color): string
    {
        return self::colorToHexString($color, '#');
    }

    /**
     * Parse une couleur SVG hexadécimale
     */
    public static function colorFromSVGHEX(string $svgHex): ?array
    {
        return self::parseColor($svgHex);
    }

    /**
     * Supprime les accents d'une chaîne
     */
    public static function unaccent(string $str): string
    {
        $str = \Normalizer::normalize($str, \Normalizer::FORM_D);
        return preg_replace('/[\x{0300}-\x{036f}]/u', '', $str);
    }

    /**
     * Convertit un entier de couleur en RGB
     * @param int $intColor
     * @return array
     */
    public static function integerToRgb(int $intColor): array
    {
        return [
            'r' => ($intColor >> 16) & 0xFF,
            'g' => ($intColor >> 8) & 0xFF,
            'b' => $intColor & 0xFF
        ];
    }

    /**
     * Convertit RGB en entier
     * @param array $color - ['r' => int, 'g' => int, 'b' => int]
     * @return int
     */
    public static function rgbToInteger(array $color): int
    {
        return (($color['r'] * 256 + $color['g']) * 256 + $color['b']);
    }

    /**
     * Évalue une couleur à partir d'une définition (peut être une interpolation)
     * @param mixed $o - String, array avec interpolation
     * @param int $zoom - Niveau de zoom (pour interpolation)
     * @return array - Couleur RGB
     */
    public static function evaluateColor($o, int $zoom = 0): array
    {
        $defaultColor = self::parseColor(self::DEFAULT_COLOR_STRING);

        if (is_array($o)) {
            // Interpolation: ["interpolate", "linear", "zoom", zoom1, color1, zoom2, color2,...]
            if (!isset($o[0]) || $o[0] !== 'interpolate') {
                return $defaultColor;
            }

            if (!isset($o[1])) {
                return $defaultColor;
            }

            $interpolType = $o[1];
            switch ($interpolType) {
                case 'linear':
                    return self::linearInterpolation($o, $zoom);
                case 'exponential':
                case 'cubic-bezier':
                default:
                    return $defaultColor;
            }
        }

        if (is_string($o)) {
            $color = self::stringToColor($o);
            if ($color !== null) {
                return $color;
            }
        }

        return $defaultColor;
    }

    /**
     * Interpolation linéaire pour zoom
     */
    private static function linearInterpolation(array $ja, int $zoom): array
    {
        $defaultColor = self::parseColor(self::DEFAULT_COLOR_STRING);

        // Format: ["interpolate", "linear", "zoom", zoom1, color1, zoom2, color2,...]
        if (count($ja) < 7) {
            return $defaultColor;
        }

        $variable = $ja[2] ?? '';
        if ($variable !== 'zoom') {
            return $defaultColor;
        }

        $value = $zoom;
        $firstStep = (int)($ja[3] ?? 0);
        $firstColor = self::stringToColor($ja[4] ?? self::DEFAULT_COLOR_STRING);

        if ($value <= $firstStep) {
            return $firstColor;
        }

        $nextStep = (int)($ja[5] ?? 0);
        $nextColor = self::stringToColor($ja[6] ?? self::DEFAULT_COLOR_STRING);

        $index = 7;
        while ($index + 1 < count($ja)) {
            if ($nextStep < $value) {
                $firstStep = $nextStep;
                $firstColor = $nextColor;
                $nextStep = (int)($ja[$index] ?? 0);
                $nextColor = self::stringToColor($ja[$index + 1] ?? self::DEFAULT_COLOR_STRING);
                $index += 2;
                continue;
            }

            // Point de partage
            $delta = ($value - $firstStep) / ($nextStep - $firstStep);
            return self::interpolateLinearHSLColor($firstColor, $nextColor, $delta);
        }

        return $nextColor;
    }
}
