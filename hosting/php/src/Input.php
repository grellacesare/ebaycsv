<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;
use Brick\Math\BigDecimal;

final class Input
{
    public static function fail(string $field, string $message): never
    {
        throw new AppError('invalid_input', 'Correggi i campi indicati.', 422, [['field'=>$field,'message'=>$message]]);
    }
    public static function fields(array $input, array $allowed, array $required=[]): array
    {
        foreach(array_keys($input) as $key) if (!in_array($key,$allowed,true)) self::fail((string)$key,'Campo non previsto.');
        foreach($required as $key) if (!array_key_exists($key,$input)) self::fail($key,'Campo obbligatorio.');
        return $input;
    }
    public static function object(mixed $value, string $field): array
    {
        if($value instanceof \stdClass) $value=(array)$value;
        if(!is_array($value)||($value!==[]&&array_is_list($value))) self::fail($field,'Inserisci un oggetto di campi.');
        return $value;
    }
    public static function text(mixed $value,string $field,int $max=10000,bool $nullable=false): ?string
    {
        if($nullable&&$value===null)return null;
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$max||str_contains($value,"\0"))self::fail($field,'Inserisci un testo valido.');
        return $value;
    }
    public static function identifier(mixed $value,string $field): string
    {
        $value=self::text($value,$field,200);
        if($value===''||preg_match('/[\/\\\\\x00-\x1f\x7f]/',$value)) self::fail($field,'Identificativo non valido.');
        return $value;
    }
    public static function integer(mixed $value,string $field,int $minimum=0,int $maximum=PHP_INT_MAX): int
    {
        if(is_string($value)&&preg_match('/\A[+-]?\d+\z/D',$value)&&strlen(ltrim($value,'+-0'))<19) $value=(int)$value;
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::fail($field,'Inserisci un numero intero nell’intervallo consentito.');
        return $value;
    }
    public static function boolean(mixed $value,string $field,bool $nullable=false): ?bool
    {
        if($nullable&&$value===null)return null;
        if(!is_bool($value))self::fail($field,'Scegli sì oppure no.');
        return $value;
    }
    public static function decimal(mixed $value,string $field,?string $maximum=null,bool $positive=false): string
    {
        if(is_int($value)||(is_float($value)&&is_finite($value)))$value=(string)$value;
        if(!is_string($value)||strlen($value)>128||!preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d{1,3})?\z/D',$value))self::fail($field,'Inserisci un importo numerico finito.');
        if(preg_match('/[eE]([+-]?\d+)$/',$value,$m)&&abs((int)$m[1])>128)self::fail($field,'Importo fuori dall’intervallo consentito.');
        try{$decimal=BigDecimal::of($value);}
        catch(\Throwable){self::fail($field,'Inserisci un importo numerico finito.');}
        if($decimal->compareTo('0')<0||($positive&&$decimal->compareTo('0')===0)||($maximum!==null&&$decimal->compareTo($maximum)>0))self::fail($field,'Importo fuori dall’intervallo consentito.');
        return $value;
    }
    public static function choice(mixed $value,string $field,array $choices): string
    {
        if(!is_string($value)||!in_array($value,$choices,true))self::fail($field,'Scegli un valore dall’elenco.');
        return $value;
    }
    public static function destination(mixed $value): string {return self::choice($value,'destination',['ebay','prestashop']);}
    public static function priceSettings(mixed $value): array
    {
        $value=self::object($value,'price_settings');
        self::fields($value,['shipping_cost','product_markup','shipping_markup','commission_rate','commission_fixed','vat_rate','quantity'],['shipping_cost','product_markup','shipping_markup','commission_rate','quantity']);
        $result=Defaults::priceSettings($value);
        foreach(['shipping_cost','product_markup','shipping_markup','commission_rate','commission_fixed','vat_rate']as$field)$result[$field]=self::decimal($result[$field],'price_settings.'.$field,in_array($field,['commission_rate','vat_rate'],true)?'1':null);
        $result['quantity']=self::integer($result['quantity'],'price_settings.quantity',1);
        return $result;
    }
    public static function profile(mixed $value): array
    {
        $value=self::object($value,'platform_profile');$destination=self::destination($value['destination']??null);$defaults=Defaults::profile($destination);
        self::fields($value,array_keys($defaults),['destination']);$result=array_replace($defaults,$value);
        foreach($result as $field=>$entry){
            if($field==='manufacturer'){$map=self::object($entry,$field);foreach($map as$key=>$val){self::text($key,$field,100);self::text($val,$field,2000);}$result[$field]=(object)$map;}
            elseif($field==='free_shipping_confirmed')$result[$field]=self::boolean($entry,$field);
            elseif($field==='return_days'){if(!in_array($entry,[14,30,60],true))self::fail($field,'Scegli 14, 30 o 60 giorni.');}
            elseif($field==='return_shipping_paid_by')$result[$field]=self::choice($entry,$field,['Buyer','Seller']);
            else $result[$field]=self::text($entry,$field,10000,$field==='tax_rule_id');
        }return $result;
    }
    public static function supplierProfile(array $value): array
    {
        self::fields($value,['id','supplier_id','name','bindings'],['id','supplier_id','name','bindings']);
        $value['id']=self::identifier($value['id'],'id');$value['supplier_id']=self::text($value['supplier_id'],'supplier_id',200);$value['name']=self::text($value['name'],'name',200);
        if(trim($value['supplier_id'])===''||trim($value['name'])==='')self::fail('supplier_id','Indica il nome del fornitore e del profilo.');
        if(!is_array($value['bindings'])||!array_is_list($value['bindings'])||count($value['bindings'])>3)self::fail('bindings','Usa un profilo per anagrafica, prezzi e pesi.');
        $roles=[];$cost=0;
        foreach($value['bindings']as$i=>$raw){
            $binding=self::object($raw,'bindings');self::fields($binding,['role','encoding','delimiter','decimal_separator','thousands_separator','column_map','weight_unit','packaging_basis_confirmed'],['role','column_map']);
            $binding=array_replace(['encoding'=>'utf-8','delimiter'=>';','decimal_separator'=>'.','thousands_separator'=>null,'weight_unit'=>null,'packaging_basis_confirmed'=>false],$binding);
            $binding['role']=self::choice($binding['role'],'role',['catalog','prices','packaging']);$roles[]=$binding['role'];
            $binding['encoding']=self::choice($binding['encoding'],'encoding',['utf-8','utf-8-sig','cp1252','iso-8859-1']);
            $binding['delimiter']=self::text($binding['delimiter'],'delimiter',1);if(strlen($binding['delimiter'])!==1||in_array($binding['delimiter'],["\r","\n",'"'],true))self::fail('delimiter','Scegli un delimitatore singolo.');
            $binding['decimal_separator']=self::choice($binding['decimal_separator'],'decimal_separator',['.',',']);
            $binding['thousands_separator']=self::text($binding['thousands_separator'],'thousands_separator',1,true);
            if($binding['thousands_separator']===$binding['decimal_separator'])self::fail('thousands_separator','I separatori devono essere distinti.');
            $map=self::object($binding['column_map'],'column_map');if(!isset($map['code'])||count($map)>200)self::fail('column_map','Scegli la colonna del codice prodotto.');
            foreach($map as$key=>$column){self::text($key,'column_map',100);self::text($column,'column_map',1000);}
            $binding['column_map']=$map;$cost+=array_key_exists('cost',$map)?1:0;
            $binding['weight_unit']=self::text($binding['weight_unit'],'weight_unit',100,true);$binding['packaging_basis_confirmed']=self::boolean($binding['packaging_basis_confirmed'],'packaging_basis_confirmed');
            $value['bindings'][$i]=$binding;
        }
        if(!in_array('catalog',$roles,true)||count($roles)!==count(array_unique($roles))||$cost!==1)self::fail('bindings','Serve un’anagrafica, un solo file per ruolo e una sola colonna del costo IVA esclusa.');
        return $value;
    }
}
