"""Render inert deployment examples; never install or change a live server."""
from pathlib import Path
import argparse,re
p=argparse.ArgumentParser();p.add_argument('--app-root',required=True);p.add_argument('--web-root',required=True);p.add_argument('--prefix',default='');p.add_argument('--php-endpoint',required=True);p.add_argument('--output',required=True);a=p.parse_args()
base=Path(__file__).resolve().parent;out=Path(a.output).resolve()
if out.exists():raise SystemExit('Output exists; choose a fresh review directory')
prefix=a.prefix.rstrip('/')
if prefix and not re.fullmatch(r'(?:/[A-Za-z0-9_-]+)+',prefix):raise SystemExit('Prefix must be empty or slash-separated ASCII names')
def absolute(value):
    value=value.replace('\\','/').rstrip('/')
    if not (value.startswith('/') or re.match(r'^[A-Za-z]:/',value)) or re.search(r'[\s;{}"\x00-\x1f]',value):raise SystemExit('Use an absolute path without whitespace/config metacharacters')
    return value
app=absolute(a.app_root);web=absolute(a.web_root)
if app!=web+prefix:raise SystemExit('App root must equal web root plus URL prefix; alias mounts are unsupported')
endpoint=a.php_endpoint
if not re.fullmatch(r'(?:unix:/[A-Za-z0-9_./-]+|(?:127\.0\.0\.1|localhost):[0-9]{1,5})',endpoint):raise SystemExit('Use a local PHP socket or loopback endpoint')
out.mkdir(parents=True)
tokens={'__URL_PREFIX__':prefix,'__APPROOT__':app,'__WEBROOT__':web,'__DEPLOYMENT_DIR__':str(out).replace('\\','/'),'__PHP_ENDPOINT__':endpoint}
for path in sorted(base.rglob('*.example')):
    rel=path.relative_to(base);dest=out/str(rel).removesuffix('.example');dest.parent.mkdir(parents=True,exist_ok=True);data=path.read_text(encoding='utf-8')
    for token,value in tokens.items():data=data.replace(token,value)
    dest.write_text(data,encoding='utf-8',newline='\n')
(out/'nginx/fastcgi-params.conf').write_bytes((base/'nginx/fastcgi-params.conf').read_bytes())
print(str(out))
