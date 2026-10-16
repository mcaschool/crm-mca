// Test the production teardown function with deterministic concurrent revocation.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('Modules/TeamChat/resources/js/widget-frame.js','utf8');
const start=source.indexOf('async function disconnect('),end=source.indexOf('function drawPreferences()',start);
assert(start>=0&&end>start);
const counts={revoke:0,audio:0,chat:0,clear:0,blob:0},status=[];
const scope={
 headers:()=>({Authorization:'MCAWidget fixture-only'}),fetch:()=>{counts.revoke++;return Promise.resolve();},
 clearInterval:()=>{},clearTimeout:()=>{},chat:{destroy:()=>counts.chat++},controller:{abort:()=>{}},popup:null,
 audio:{state:'running',close:()=>{counts.audio++;return Promise.resolve();}},
 URL:{revokeObjectURL:()=>counts.blob++},$:(s)=>({replaceChildren:()=>counts.clear++,hidden:false}),
 status:t=>status.push(t),post:()=>{},window:{},en:false,
};
vm.createContext(scope);
vm.runInContext("let token='fixture-only',closed=false,heartTimer=null,alertTimer=null,renewTimer=null;const blobs=new Set(['blob:fixture']);"+source.slice(start,end),scope);
(async()=>{
 await vm.runInContext('Promise.all([disconnect(),disconnect(),disconnect(false)])',scope);
 assert.equal(counts.revoke,1);assert.equal(counts.audio,1);assert.equal(counts.chat,1);
 assert.equal(counts.clear,1);assert.equal(counts.blob,1);assert.equal(status.length,1);
 assert.equal(vm.runInContext('closed&&token===null&&blobs.size===0',scope),true);
 console.log('WIDGET CLIENT CONCURRENT TEARDOWN PASS: 7 assertions');
})().catch(e=>{console.error(e);process.exitCode=1;});
