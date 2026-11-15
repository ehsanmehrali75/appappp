"""Cross-project query and lock regressions; isolated databases, no live traffic."""
import unittest,sqlite3,subprocess,json,time,threading
from test_http_security import HttpSecurity as Base,PHP

class PanelPerformance(unittest.TestCase):
    PHP_SETTINGS=()
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    setUp=Base.setUp
    request=Base.request
    login=Base.login
    def php(self,code):
        r=subprocess.run([PHP,'-r',"require $argv[1].'/panel/inc/functions.php';"+code,str(self.app)],capture_output=True,text=True,timeout=20)
        self.assertEqual(r.returncode,0,r.stderr);return json.loads(r.stdout)
    def test_directory_does_not_hydrate_other_project_products(self):
        with sqlite3.connect(self.database) as db:
            db.execute("INSERT INTO projects(id,name,settings) VALUES ('large','Large','{}')")
            db.executemany('INSERT INTO products(id,project_id,name,description) VALUES (?,?,?,?)',[(f'large-{i}','large','Foreign product','x'*10000) for i in range(500)])
        rows=self.php("$d=getProjectDirectory(true);echo json_encode(array_map(fn($p)=>['id'=>$p['id'],'count'=>$p['product_count'],'has_products'=>array_key_exists('products',$p)],$d['projects']));")
        row=next(r for r in rows if r['id']=='large');self.assertEqual(row,{'id':'large','count':500,'has_products':False})
        self.assertEqual(self.php("echo json_encode(count(getCurrentProject('p',true)['products']));"),5)
    def test_schema_revision_is_durable_and_project_lookup_uses_index(self):
        self.assertTrue(self.php("require_once $argv[1].'/panel/inc/DatabaseSchema.php';echo json_encode(DatabaseSchema::ready(getConn()));"))
        with sqlite3.connect(self.database) as db:plan=db.execute("EXPLAIN QUERY PLAN SELECT * FROM products WHERE project_id='p'").fetchone()[3]
        self.assertIn('idx_maarksho_products_project',plan)
        self.assertTrue(self.php("require_once $argv[1].'/panel/inc/DatabaseSchema.php';echo json_encode(DatabaseSchema::ready(getConn()));"))
    def test_warm_schema_does_not_block_readers_behind_a_writer(self):
        opener,_=self.login();held=threading.Event();release=threading.Event();errors=[]
        def writer():
            try:
                with sqlite3.connect(self.database) as db:
                    db.execute('BEGIN IMMEDIATE');db.execute("UPDATE products SET name='Uncommitted writer' WHERE id='one'");held.set();release.wait(5);db.rollback()
            except Exception as e:errors.append(str(e));held.set()
        thread=threading.Thread(target=writer);thread.start();self.assertTrue(held.wait(5))
        try:
            start=time.monotonic();status,body=self.request(opener,'/panel/admin.php?tab=products');elapsed=time.monotonic()-start
            self.assertEqual(status,200);self.assertLess(elapsed,2.0,'Read request waited for the unrelated SQL writer');self.assertIn('محصول one',body)
        finally:release.set();thread.join(6)
        self.assertFalse(errors)

del Base
