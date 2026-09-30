# Test ALTCHA endpoint

Run this in browser console:

```javascript
fetch('/altcha/request?_=2').then(r => { console.log('status:', r.status, 'type:', r.headers.get('content-type')); return r.text(); }).then(t => console.log('body:', t.slice(0, 100)))
```
