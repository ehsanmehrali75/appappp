"""Byte caps on actual GD derivatives and isolated Studio HTTP writes; no production files."""
import base64,json,sqlite3,subprocess,unittest,uuid
import test_http_security as fixtures

class SizeChartCompression(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('CompressedChartFixture',(fixtures.HttpSecurity,),{'PHP_SETTINGS':('-d','upload_max_filesize=16M','-d','post_max_size=32M')});cls.fixture.setUpClass()
        cls.noise=cls.fixture.app/'chart-noise.jpg'
        proc=subprocess.run([fixtures.PHP,'-r',"mt_srand(1901);$im=imagecreatetruecolor(1000,1400);for($y=0;$y<1400;$y++)for($x=0;$x<1000;$x++)imagesetpixel($im,$x,$y,mt_rand(0,16777215));imagejpeg($im,$argv[1],90);",str(cls.noise)],capture_output=True)
        if proc.returncode:raise RuntimeError(proc.stderr)
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp();self.opener,self.token=self.case.login()
        self.payload='data:image/jpeg;base64,'+base64.b64encode(self.noise.read_bytes()).decode()
    def request(self,action='td_scs_chart_image_upload',**fields):
        code,body=self.case.request(self.opener,'/panel/size/?project=p',{'action':action,**fields},{'X-CSRF-Token':self.token,'X-Project-Context':'p'})
        if not body.lstrip().startswith('{'):
            self.fail(str(code)+' response is not JSON: '+body[:300])
        return code,json.loads(body)
    def php(self,source):
        p=subprocess.run([fixtures.PHP,'-r',"require $argv[1];"+source,str(self.case.app/'panel/inc/SizeChartImage.php'),str(self.noise)],capture_output=True,text=True)
        self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
    def test_both_export_formats_obey_exact_byte_limit_and_keep_aspect_ratio(self):
        self.assertGreater(self.noise.stat().st_size,400000)
        result=self.php("$out=[];foreach(['jpeg','webp'] as $f){$bytes=SizeChartImage::compress(file_get_contents($argv[2]),$f);$info=getimagesizefromstring($bytes);$out[]=[$f,strlen($bytes),$info[0],$info[1],$info['mime']];}echo json_encode($out);")
        for format,size,w,h,mime in result:
            self.assertLessEqual(size,400000);self.assertGreater(size,0);self.assertGreaterEqual(w,400);self.assertAlmostEqual(w/h,1000/1400,delta=.005)
            self.assertEqual(mime,'image/'+format)
    def test_jpeg_orientation_is_applied_before_metadata_is_removed(self):
        result=self.php("$s=file_get_contents($argv[2]);$exif=hex2bin('45786966000049492a0008000000010012010300010000000600000000000000');$s=substr($s,0,2).chr(255).chr(225).pack('n',strlen($exif)+2).$exif.substr($s,2);$out=SizeChartImage::compress($s);$i=getimagesizefromstring($out);echo json_encode([$i[0],$i[1],strlen($out)]);")
        self.assertAlmostEqual(result[0]/result[1],1.4,delta=.005);self.assertLessEqual(result[2],400000)

    def test_server_compresses_large_payload_and_does_not_overwrite_existing_file(self):
        original=(self.case.app/'panel/uploads/original.png').read_bytes()
        code,res=self.request(image_data=self.payload,existing_image_path='uploads/original.png');self.assertEqual(code,200,res)
        data=res['data'];path=self.case.app/'panel'/data['url'];self.assertTrue(path.is_file());self.assertLessEqual(path.stat().st_size,400000)
        self.assertEqual(data['bytes'],path.stat().st_size);self.assertTrue(data['url'].endswith('.jpg'))
        self.assertEqual((self.case.app/'panel/uploads/original.png').read_bytes(),original)
    def test_invalid_payload_cannot_create_a_file(self):
        before=set((self.case.app/'panel/uploads').glob('*'))
        for payload in ['garbage','data:image/jpeg;base64,'+base64.b64encode(b'not an image').decode(),'data:text/html;base64,PHNjcmlwdD4=']:
            code,res=self.request(image_data=payload);self.assertEqual(code,422,res);self.assertFalse(res['success'])
        self.assertEqual(set((self.case.app/'panel/uploads').glob('*')),before)
    def test_batch_import_compresses_rendered_chart_before_saving(self):
        code,res=self.request('td_scs_import_charts_json',json_data=json.dumps([{'title':'Compression fixture','category':'شلوار','columns':['سایز','قد'],'rows':[['M','100']],'rendered_image_data_url':self.payload}]))
        self.assertEqual(code,200,res);self.assertTrue(res['success'])
        with sqlite3.connect(self.case.database) as db:settings=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])
        image=settings['size_charts'][0]['image_path'];self.assertLessEqual((self.case.app/'panel'/image).stat().st_size,400000)
    def upload(self,path,fields,file_field):
        boundary='----chart-fixture-'+uuid.uuid4().hex;body=bytearray()
        for key,value in fields.items():body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="chart.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'.encode());body.extend(self.noise.read_bytes());body.extend(f'\r\n--{boundary}--\r\n'.encode())
        return self.case.request(self.opener,path,headers={'X-CSRF-Token':self.token,'X-Project-Context':'p','Content-Type':'multipart/form-data; boundary='+boundary},raw=bytes(body))
    def test_manual_studio_upload_is_reencoded_before_linking(self):
        code,body=self.upload('/panel/size/?project=p',{'action':'td_scs_direct_upload_chart','product_id':'one'},'chart_file');self.assertEqual(code,200,body[:300])
        result=json.loads(body);self.assertTrue(result['success']);path=self.case.app/'panel'/result['image_path'];self.assertLessEqual(path.stat().st_size,400000)
        self.assertEqual(self.case.record()['size_chart'],result['image_path'])
    def test_manual_admin_edit_compresses_only_chart_and_preserves_product_photo(self):
        before=self.case.record()['image']
        code,body=self.upload('/panel/admin.php?tab=products',{'update_product_data':'1','pid':'one','name':'Compressed chart','price':'10','status':'active'},'size_chart_file');self.assertEqual(code,200,body[:300])
        record=self.case.record();self.assertEqual(record['image'],before);self.assertTrue(record['size_chart'])
        self.assertLessEqual((self.case.app/'panel'/record['size_chart']).stat().st_size,400000)

    def test_chatgpt_content_api_cannot_bypass_the_chart_byte_cap(self):
        result=self.php("require_once dirname($argv[1]).'/ai/Bootstrap.php';SessionIdentity::beginGlobal('fixture_owner');SessionIdentity::select(getCurrentProject('p',true));$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$grant['scopes']=['content.write'];$out=(new AiContent(new AiStore()))->save($grant,['product_id'=>'one','kind'=>'size_chart','request_id'=>'audit-chart-byte-cap','base64'=>base64_encode(file_get_contents($argv[2]))]);$p=getCurrentProject('p',true);foreach($p['products'] as $prod)if($prod['id']==='one')$url=$prod['size_chart'];$path=dirname($argv[1]).'/../'.preg_replace('~^/panel/~','',$url);echo json_encode([filesize($path),getimagesize($path)['mime']]);")
        self.assertLessEqual(result[0],400000);self.assertEqual(result[1],'image/jpeg')

    def test_zip_chart_variants_each_obey_limit(self):
        result=self.php("require dirname($argv[1]).'/WebsiteExportPackage.php';$m=new ReflectionMethod('WebsiteExportPackage','pair');$m->setAccessible(true);$out=$m->invoke(null,file_get_contents($argv[2]),false);echo json_encode(array_map('strlen',$out));")
        self.assertEqual(set(result),{'jpg','webp'});self.assertTrue(all(0<n<=400000 for n in result.values()))
